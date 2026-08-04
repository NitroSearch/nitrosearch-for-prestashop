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

use CMS;
use Context;
use NitroSearch\AdapterKit\ItemBuilder;
use NitroSearch\Settings;
use Tools;
use Validate;

/**
 * Turns one PrestaShop CMS page into one wire item.
 *
 * CMS pages share the merchant's plan allowance with products, so they are only
 * walked when the merchant has switched them on — and the service independently
 * lets products claim capacity first, because a server cannot trust a client to
 * be well behaved about someone else's quota.
 *
 * A null return means "must not be indexed", which the caller turns into a
 * DELETE.
 */
final class CmsSerializer
{
    /**
     * @param int $id
     *
     * @return array<string, mixed>|null
     */
    public static function serialize($id)
    {
        $id = (int) $id;

        if (!(bool) Settings::get('INDEX_CMS')) {
            return null;
        }

        $context = Context::getContext();
        $idLang = (int) $context->language->id;

        $page = new CMS($id, $idLang);
        if (!Validate::isLoadedObject($page) || !(bool) $page->active) {
            return null;
        }

        // 'page' rather than 'product'. STATING THE TYPE IS LOAD-BEARING on this
        // platform: PrestaShop's id_product and id_cms are separate sequences, so
        // product 12 and CMS page 12 both exist in an ordinary shop. The service
        // namespaces our document ids by type precisely so those two cannot
        // collide on one document — and it can only do that if we say which is
        // which. A bare id would be ambiguous.
        $item = ItemBuilder::content($id, 'page')
            ->name((string) $page->meta_title)
            ->visible(true)
            ->version((int) round(microtime(true) * 1000));

        $url = (string) $context->link->getCMSLink($page);
        if ($url !== '') {
            $item->permalink($url);
        }

        $excerpt = trim(strip_tags((string) $page->meta_description));
        if ($excerpt === '') {
            $excerpt = trim(strip_tags((string) $page->content));
        }
        if ($excerpt !== '') {
            $item->excerpt(Tools::substr($excerpt, 0, 500));
        }

        return $item->toArray();
    }
}
