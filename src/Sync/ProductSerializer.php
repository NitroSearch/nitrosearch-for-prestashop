<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

namespace NitroSearch\Sync;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Cart;
use Category;
use Configuration;
use Context;
use Customer;
use Group;
use Manufacturer;
use NitroSearch\AdapterKit\CurrencyExponents;
use NitroSearch\AdapterKit\ItemBuilder;
use NitroSearch\AdapterKit\Money;
use Product;
use StockAvailable;
use Tools;
use Validate;

/**
 * Turns one PrestaShop product into one wire item.
 *
 * ONE PRODUCT IS ONE OBJECT, however many combinations it has. Combinations are
 * nested as variants, never sent as top-level items: sending them separately
 * fills a shopper's results with near-duplicates AND multiplies what the merchant
 * is charged, because the billable unit is the product.
 *
 * A null return means "this must not be indexed" — disabled, deleted, or not
 * visible to search. The caller turns that into a DELETE, so a product that stops
 * being public leaves the index rather than lingering in it.
 */
final class ProductSerializer
{
    /**
     * @param int $id
     *
     * @return array<string, mixed>|null null when the product must not be indexed
     */
    public static function serialize($id)
    {
        $id = (int) $id;
        self::ensurePricingContext();

        $context = Context::getContext();
        $idLang = (int) $context->language->id;

        $product = new Product($id, true, $idLang);
        if (!Validate::isLoadedObject($product)) {
            return null;
        }

        if (!self::isIndexable($product)) {
            return null;
        }

        $currency = self::currencyIso();
        $item = ItemBuilder::product($id)
            ->name((string) $product->name)
            ->visible(true)
            ->version(self::version($product));

        $link = $context->link;
        $url = (string) $link->getProductLink($product);
        if ($url !== '') {
            $item->permalink($url);
        }

        $image = self::imageUrl($product, $idLang);
        if ($image !== '') {
            $item->image($image);
        }

        $price = self::price($id, null);
        if ($price !== null) {
            $item->price(Money::fromDecimalString($price, $currency));
        }

        $reference = trim((string) $product->reference);
        if ($reference !== '') {
            $item->sku($reference);
        }

        $brand = self::brand($product);
        if ($brand !== '') {
            $item->brand($brand);
        }

        $description = trim(strip_tags((string) $product->description_short));
        if ($description === '') {
            $description = trim(strip_tags((string) $product->description));
        }
        if ($description !== '') {
            $item->description(Tools::substr($description, 0, 1000));
        }

        $categories = self::categoryNames($product, $idLang);
        if (!empty($categories)) {
            $item->categories($categories);
        }

        $item->inStock(self::inStock($product));
        $item->onSale(self::onSale($product, $price));

        self::addVariants($item, $product, $currency, $idLang);

        return $item->toArray();
    }

    /**
     * Give PrestaShop a cart to price against.
     *
     * WITHOUT THIS THE MODULE WORKS IN THE BACK OFFICE AND FAILS IN PRODUCTION,
     * which is the worst shape a bug can have. `Product::getPriceStatic()` needs
     * one of three things — a cart object in the context, an explicit id_cart, or
     * an employee — and if it has none it calls `die()`:
     *
     *   "If no employee is assigned in the context, cart ID must be provided"
     *
     * A merchant saving a product has an employee, so every manual test passes. A
     * cron tick and a deferred page-load drain have neither employee nor cart, so
     * the real sync path — the one that carries the whole catalogue — dies on the
     * FIRST product, taking the request with it. Not an exception: a `die()`, so
     * there is nothing to catch and the response is a bare error string.
     *
     * An empty, unsaved Cart is the honest fix rather than a workaround. Prices
     * are indexed as an anonymous visitor with an empty basket sees them, which is
     * exactly the price a shopper is shown before they add anything — the number
     * our results must agree with.
     */
    private static function ensurePricingContext()
    {
        $context = Context::getContext();

        if (!is_object($context->cart) || !($context->cart instanceof Cart)) {
            $context->cart = new Cart();
        }

        // A shop can price differently per customer group. With no customer we
        // want the DEFAULT group's prices, which is what Group::getCurrent()
        // returns for an anonymous visitor — the same basis as the storefront.
        if (!is_object($context->customer)) {
            $context->customer = new Customer();
        }
    }

    /**
     * Whether this product may appear in a public search index.
     *
     * FAILS CLOSED, and each condition is a real way a merchant hides a product:
     *
     * - `active` is the obvious one.
     * - `visibility` is the one that catches people out. PrestaShop offers
     *   `both` / `catalog` / `search` / `none`, and a product set to `catalog` is
     *   deliberately excluded from the shop's OWN search. Indexing it anyway would
     *   make our search show what the merchant configured their search to hide —
     *   the single most visible way to be wrong about someone's catalogue.
     * - `available_for_order` is NOT checked: a display-only product still belongs
     *   in results, it simply cannot be bought.
     *
     * @param Product $product
     *
     * @return bool
     */
    private static function isIndexable(Product $product)
    {
        if (!(bool) $product->active) {
            return false;
        }

        $visibility = (string) $product->visibility;

        return $visibility === 'both' || $visibility === 'search';
    }

    /**
     * The price as a DECIMAL STRING, for Money::fromDecimalString.
     *
     * NEVER `(int) round($price * 100)`. That is wrong for about fifty currencies
     * — yen has no minor unit, Kuwaiti dinar has three — and it is wrong even for
     * two-decimal currencies because 19.99 is not representable in binary and
     * `(int) (19.99 * 100)` is 1998. The kit scales by moving digits instead, so
     * it hands the string over rather than a float.
     *
     * TAX FOLLOWS THE SHOP'S OWN DISPLAY SETTING. There is one price field on the
     * wire and the service performs no tax calculation of any kind, so the only
     * defensible value is the one this shop shows its shoppers — otherwise the
     * price in our results contradicts the price on the product page.
     *
     * @param int      $idProduct
     * @param int|null $idAttribute a combination, or null for the base product
     *
     * @return string|null
     */
    private static function price($idProduct, $idAttribute)
    {
        $useTax = self::displaysPricesWithTax();

        $price = Product::getPriceStatic(
            (int) $idProduct,
            $useTax,
            $idAttribute === null ? null : (int) $idAttribute,
            // Six decimals, then formatted below: asking PrestaShop to round to the
            // currency's precision here would round twice, once by it and once by
            // the kit, and a half-cent product would land a cent out.
            6
        );

        if ($price === null || $price === false) {
            return null;
        }

        return self::decimalString((float) $price);
    }

    /**
     * Format a float as a plain decimal string with the currency's own precision.
     *
     * `number_format` with a fixed 2 would corrupt yen and dinar. The exponent
     * comes from the kit's generated table, which is derived from the service's
     * own — so the two cannot disagree about how many places a currency has.
     *
     * @param float $value
     *
     * @return string
     */
    private static function decimalString($value)
    {
        $exponent = CurrencyExponents::for(self::currencyIso());

        return number_format($value, $exponent, '.', '');
    }

    /**
     * @return bool whether this shop shows tax-inclusive prices
     */
    private static function displaysPricesWithTax()
    {
        // The default customer group's display method: 0 = tax included,
        // 1 = tax excluded (PS_TAX_INC / PS_TAX_EXC).
        $idGroup = (int) Configuration::get('PS_CUSTOMER_GROUP');
        $method = (int) Group::getPriceDisplayMethod($idGroup);

        return $method === 0;
    }

    /**
     * @return string ISO 4217 code for the shop's default currency
     */
    private static function currencyIso()
    {
        $context = Context::getContext();
        if ($context->currency && $context->currency->iso_code) {
            return strtoupper((string) $context->currency->iso_code);
        }

        return 'EUR';
    }

    /**
     * The last-write-wins clock for this item.
     *
     * PrestaShop stamps `date_upd` on save, which is second-resolution — too
     * coarse on its own, because two edits in the same second would tie and the
     * service skips an item whose version is not GREATER than the one it holds.
     * Milliseconds-now is used instead: it always advances, and the value only
     * ever needs to order our own sends.
     *
     * @param Product $product
     *
     * @return int
     */
    private static function version(Product $product)
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * @param Product $product
     * @param int     $idLang
     *
     * @return string
     */
    private static function imageUrl(Product $product, $idLang)
    {
        $cover = Product::getCover((int) $product->id);
        if (!$cover || empty($cover['id_image'])) {
            return '';
        }

        $rewrite = $product->link_rewrite;
        if (is_array($rewrite)) {
            $rewrite = isset($rewrite[$idLang]) ? $rewrite[$idLang] : reset($rewrite);
        }

        return (string) Context::getContext()->link->getImageLink(
            (string) $rewrite,
            (int) $cover['id_image'],
            'home_default'
        );
    }

    /**
     * @param Product $product
     *
     * @return string
     */
    private static function brand(Product $product)
    {
        if (!(int) $product->id_manufacturer) {
            return '';
        }

        $name = Manufacturer::getNameById((int) $product->id_manufacturer);

        return $name ? (string) $name : '';
    }

    /**
     * @param Product $product
     * @param int     $idLang
     *
     * @return array<int, string>
     */
    private static function categoryNames(Product $product, $idLang)
    {
        $ids = $product->getCategories();
        if (!is_array($ids) || empty($ids)) {
            return array();
        }

        $rootId = (int) Configuration::get('PS_ROOT_CATEGORY');
        $homeId = (int) Configuration::get('PS_HOME_CATEGORY');

        $names = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            // The root and Home categories are structural — every product is in
            // them, so a facet listing them says nothing and pushes the useful
            // values down the list.
            if ($id === $rootId || $id === $homeId) {
                continue;
            }

            $category = new Category($id, $idLang);
            if (Validate::isLoadedObject($category) && (string) $category->name !== '') {
                $names[] = (string) $category->name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param Product $product
     *
     * @return bool
     */
    private static function inStock(Product $product)
    {
        // A product that is allowed to be ordered when out of stock is, for a
        // shopper's purposes, in stock — the badge exists to stop them clicking
        // something they cannot buy.
        if ((int) $product->out_of_stock === 1) {
            return true;
        }

        return (int) StockAvailable::getQuantityAvailableByProduct((int) $product->id) > 0;
    }

    /**
     * @param Product     $product
     * @param string|null $currentPrice
     *
     * @return bool
     */
    private static function onSale(Product $product, $currentPrice)
    {
        if ((bool) $product->on_sale) {
            return true;
        }
        if ($currentPrice === null) {
            return false;
        }

        // A specific price (a catalogue rule or a per-product discount) shows up
        // as the sell price being below the base one.
        $base = Product::getPriceStatic(
            (int) $product->id,
            self::displaysPricesWithTax(),
            null,
            6,
            null,
            false,
            false // usereduc = false: the price BEFORE any reduction
        );

        if ($base === null || $base === false) {
            return false;
        }

        return (float) $base > (float) $currentPrice + 0.00001;
    }

    /**
     * Nest every combination as a variant.
     *
     * Their SKUs become searchable, their attributes become facets, and the
     * indexed price becomes the range across the parent and every combination —
     * all without any of them becoming a separate result or a separate billable
     * object.
     *
     * @param ItemBuilder $item
     * @param Product     $product
     * @param string      $currency
     * @param int         $idLang
     */
    private static function addVariants(ItemBuilder $item, Product $product, $currency, $idLang)
    {
        $combinations = $product->getAttributeCombinations($idLang);
        if (!is_array($combinations) || empty($combinations)) {
            return;
        }

        // getAttributeCombinations returns ONE ROW PER ATTRIBUTE, not per
        // combination — a Size+Colour combination is two rows sharing an
        // id_product_attribute. Grouping first is what stops each combination
        // being emitted several times, each carrying only one of its attributes.
        $grouped = array();
        foreach ($combinations as $row) {
            $idAttribute = (int) $row['id_product_attribute'];
            if (!isset($grouped[$idAttribute])) {
                $grouped[$idAttribute] = array(
                    'reference' => isset($row['reference']) ? (string) $row['reference'] : '',
                    'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : 0,
                    'attributes' => array(),
                );
            }

            $group = isset($row['group_name']) ? (string) $row['group_name'] : '';
            $value = isset($row['attribute_name']) ? (string) $row['attribute_name'] : '';
            if ($group !== '' && $value !== '') {
                $grouped[$idAttribute]['attributes'][$group][] = $value;
            }
        }

        foreach ($grouped as $idAttribute => $combination) {
            $price = self::price((int) $product->id, $idAttribute);
            if ($price === null) {
                continue;
            }

            $attributes = array();
            foreach ($combination['attributes'] as $group => $values) {
                $attributes[$group] = array_values(array_unique($values));
            }

            $item->variant(
                $idAttribute,
                $combination['reference'],
                Money::fromDecimalString($price, $currency),
                $combination['quantity'] > 0 || (int) $product->out_of_stock === 1,
                $attributes
            );
        }
    }
}
