{*
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 *}

{foreach from=$nitro_confirmations item=message}
  <div class="alert alert-success">{$message|escape:'html':'UTF-8'}</div>
{/foreach}
{foreach from=$nitro_errors item=message}
  <div class="alert alert-danger">{$message|escape:'html':'UTF-8'}</div>
{/foreach}

{* ── Status: the first thing a merchant needs, so it leads ─────────────────── *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-dashboard"></i> {l s='Status' d='Modules.Nitrosearch.Admin'}
  </div>

  {if $nitro_state == 'disconnected'}

    <p>
      {l s='This shop is not connected yet. Connecting creates your search index and starts sending your catalogue — it does not change anything about your products.' d='Modules.Nitrosearch.Admin'}
    </p>

    <form method="post" action="{$nitro_action_url|escape:'html':'UTF-8'}">
      <input type="hidden" name="nitro_token" value="{$nitro_token|escape:'html':'UTF-8'}">
      <div class="form-group">
        <label>{l s='NitroSearch address' d='Modules.Nitrosearch.Admin'}</label>
        <input type="text" name="nitro_api_url" class="form-control"
               value="{$nitro_api_url|escape:'html':'UTF-8'}">
        <p class="help-block">
          {l s='Leave this as it is unless you were given a different address.' d='Modules.Nitrosearch.Admin'}
        </p>
      </div>
      <div class="form-group">
        <label>{l s='Invitation code (optional)' d='Modules.Nitrosearch.Admin'}</label>
        <input type="password" name="nitro_connect_token" class="form-control" autocomplete="off"
               placeholder="{if $nitro_has_connect_token}{l s='A code is saved. Type a new one to replace it.' d='Modules.Nitrosearch.Admin'}{/if}">
      </div>
      <button type="submit" name="submitNitroConnect" class="btn btn-primary">
        <i class="icon-plug"></i> {l s='Connect' d='Modules.Nitrosearch.Admin'}
      </button>
    </form>

  {elseif $nitro_state == 'unverified'}

    <div class="alert alert-warning">
      <p><strong>{l s='Almost there — we still need to confirm you control this domain.' d='Modules.Nitrosearch.Admin'}</strong></p>
      <p>
        {l s='NitroSearch checks this by making one request to your shop from the outside. Nothing can be indexed until it succeeds, which is what stops someone else indexing a shop that is not theirs.' d='Modules.Nitrosearch.Admin'}
      </p>
      <p>
        {l s='If your shop is not publicly reachable yet — a local install, a staging site, or behind a password — this is expected. Come back once it is live.' d='Modules.Nitrosearch.Admin'}
      </p>
    </div>

    <form method="post" action="{$nitro_action_url|escape:'html':'UTF-8'}" class="form-inline">
      <input type="hidden" name="nitro_token" value="{$nitro_token|escape:'html':'UTF-8'}">
      <button type="submit" name="submitNitroVerify" class="btn btn-primary">
        <i class="icon-refresh"></i> {l s='Try again' d='Modules.Nitrosearch.Admin'}
      </button>
      <button type="submit" name="submitNitroDisconnect" class="btn btn-default">
        {l s='Disconnect' d='Modules.Nitrosearch.Admin'}
      </button>
    </form>

  {else}

    <div class="row">
      <div class="col-lg-6">
        <p>
          <span class="badge badge-success">{l s='Connected' d='Modules.Nitrosearch.Admin'}</span>
          {if $nitro_has_search_key}
            <span class="badge badge-success">{l s='Search is live' d='Modules.Nitrosearch.Admin'}</span>
          {/if}
          {if $nitro_plan}
            <span class="badge">{$nitro_plan|escape:'html':'UTF-8'}</span>
          {/if}
        </p>

        {if $nitro_limit > 0}
          <p>
            <strong>{$nitro_count|intval}</strong> / {$nitro_limit|intval}
            {l s='items indexed' d='Modules.Nitrosearch.Admin'}
          </p>
          <div class="progress" style="height:10px;">
            <div class="progress-bar{if $nitro_at_limit} progress-bar-danger{/if}"
                 style="width:{$nitro_usage_pct|intval}%"></div>
          </div>
          {if $nitro_at_limit}
            <div class="alert alert-warning" style="margin-top:10px;">
              {l s='Your plan is full, so new products are not being added. Everything already indexed keeps working.' d='Modules.Nitrosearch.Admin'}
            </div>
          {/if}
        {else}
          <p><strong>{$nitro_count|intval}</strong> {l s='items indexed' d='Modules.Nitrosearch.Admin'}</p>
        {/if}
      </div>

      <div class="col-lg-6">
        <table class="table">
          <tr>
            <td>{l s='Waiting to send' d='Modules.Nitrosearch.Admin'}</td>
            <td>
              <strong>{$nitro_pending|intval}</strong>
              {if $nitro_pending > 0}
                <small class="text-muted">{l s='(sending in the background)' d='Modules.Nitrosearch.Admin'}</small>
              {/if}
            </td>
          </tr>
          {if $nitro_full_sync_active}
            <tr>
              <td>{l s='Full sync' d='Modules.Nitrosearch.Admin'}</td>
              <td>
                <span class="badge badge-warning">{l s='in progress' d='Modules.Nitrosearch.Admin'}</span>
                <small class="text-muted">{$nitro_full_sync_phase|escape:'html':'UTF-8'}</small>
              </td>
            </tr>
          {/if}
          <tr>
            <td>{l s='Last sent' d='Modules.Nitrosearch.Admin'}</td>
            <td>
              {if $nitro_last_sync}
                {$nitro_last_sync|escape:'html':'UTF-8'} <small class="text-muted">UTC</small>
              {else}
                <span class="text-muted">{l s='never' d='Modules.Nitrosearch.Admin'}</span>
              {/if}
            </td>
          </tr>
          {if $nitro_pending_reports > 0}
            <tr>
              <td>{l s='Orders to report' d='Modules.Nitrosearch.Admin'}</td>
              <td>{$nitro_pending_reports|intval} <small class="text-muted">{l s='(sent in the background)' d='Modules.Nitrosearch.Admin'}</small></td>
            </tr>
          {/if}
          {if $nitro_items_total > 0}
            <tr>
              <td>{l s='Sent in total' d='Modules.Nitrosearch.Admin'}</td>
              <td>{$nitro_items_total|intval}
                {if $nitro_avg_batch_ms > 0}
                  <small class="text-muted">({$nitro_avg_batch_ms|intval} ms {l s='per batch' d='Modules.Nitrosearch.Admin'})</small>
                {/if}
              </td>
            </tr>
          {/if}
        </table>
      </div>
    </div>

    {if $nitro_last_error}
      <div class="alert alert-warning">
        <strong>{l s='Last error' d='Modules.Nitrosearch.Admin'}:</strong>
        <code>{$nitro_last_error|escape:'html':'UTF-8'}</code>
        <p style="margin-top:8px;">
          {l s='Nothing has been lost — anything not yet sent stays queued and will be retried.' d='Modules.Nitrosearch.Admin'}
        </p>
      </div>
    {/if}

    <form method="post" action="{$nitro_action_url|escape:'html':'UTF-8'}" class="form-inline">
      <input type="hidden" name="nitro_token" value="{$nitro_token|escape:'html':'UTF-8'}">
      <button type="submit" name="submitNitroStatus" class="btn btn-default">
        <i class="icon-refresh"></i> {l s='Refresh' d='Modules.Nitrosearch.Admin'}
      </button>
      {if $nitro_pending > 0}
        <button type="submit" name="submitNitroDrain" class="btn btn-default">
          <i class="icon-send"></i> {l s='Send now' d='Modules.Nitrosearch.Admin'}
        </button>
      {/if}
      <button type="submit" name="submitNitroFullSync" class="btn btn-default">
        <i class="icon-repeat"></i> {l s='Re-send everything' d='Modules.Nitrosearch.Admin'}
      </button>
      {* No pull-right: with four buttons in a form-inline it floats onto its own
         line and reads as a separate, more prominent control than the ones it sits
         under — which is the opposite of what a destructive action wants. *}
      <button type="submit" name="submitNitroDisconnect" class="btn btn-default"
              onclick="return confirm('{l s='Disconnect this shop from NitroSearch? Your products and pages are not affected.' d='Modules.Nitrosearch.Admin' js=1}');">
        {l s='Disconnect' d='Modules.Nitrosearch.Admin'}
      </button>
    </form>

  {/if}
</div>

{* ── Keeping it in sync ────────────────────────────────────────────────────── *}
{if $nitro_state == 'ready'}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-time"></i> {l s='Keeping it up to date' d='Modules.Nitrosearch.Admin'}
  </div>

  <p>
    {l s='Changes are sent in small batches in the background. For the fastest updates, point your hosting scheduler at this address every 5 minutes:' d='Modules.Nitrosearch.Admin'}
  </p>

  <div class="form-group">
    <input type="text" class="form-control" readonly
           onclick="this.select();"
           value="{$nitro_cron_url|escape:'html':'UTF-8'}">
    <p class="help-block">
      {l s='This address is unique to your shop — keep it private. Anyone who has it can make your shop do sync work.' d='Modules.Nitrosearch.Admin'}
    </p>
  </div>

  <div class="alert alert-info">
    {l s='No scheduler? It still works. The module does a little sending after a page has already been shown to a shopper, so nobody ever waits for it. A first sync of a big catalogue just takes longer.' d='Modules.Nitrosearch.Admin'}
  </div>
</div>
{/if}

{if $nitro_multistore || $nitro_other_currencies}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-info-circle"></i> {l s='What gets indexed' d='Modules.Nitrosearch.Admin'}
  </div>
  <p>
    {l s='NitroSearch indexes one shop, in one language, at one currency. This shop is indexed in' d='Modules.Nitrosearch.Admin'}
    <strong>{$nitro_currency|escape:'html':'UTF-8'}</strong>.
  </p>
  {if $nitro_other_currencies}
    <div class="alert alert-info">
      {l s='Your other active currencies are not indexed separately, so search shows every price in' d='Modules.Nitrosearch.Admin'}
      <strong>{$nitro_currency|escape:'html':'UTF-8'}</strong>:
      {foreach from=$nitro_other_currencies item=iso name=cur}{$iso|escape:'html':'UTF-8'}{if !$smarty.foreach.cur.last}, {/if}{/foreach}.
    </div>
  {/if}
  {if $nitro_other_shops}
    <div class="alert alert-warning">
      <strong>{l s='Multistore is on, and only your default shop is indexed.' d='Modules.Nitrosearch.Admin'}</strong>
      {l s='These shops are not:' d='Modules.Nitrosearch.Admin'}
      {foreach from=$nitro_other_shops item=shop name=sh}{$shop|escape:'html':'UTF-8'}{if !$smarty.foreach.sh.last}, {/if}{/foreach}.
      {l s='Their shoppers still get your theme’s own search. Connect them as separate NitroSearch stores if you need them indexed.' d='Modules.Nitrosearch.Admin'}
    </div>
  {/if}
</div>
{/if}

{* ── Settings ──────────────────────────────────────────────────────────────── *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-cogs"></i> {l s='Settings' d='Modules.Nitrosearch.Admin'}
  </div>

  <form method="post" action="{$nitro_action_url|escape:'html':'UTF-8'}">
    <input type="hidden" name="nitro_token" value="{$nitro_token|escape:'html':'UTF-8'}">

    <div class="form-group">
      <label>{l s='Also search my CMS pages' d='Modules.Nitrosearch.Admin'}</label>
      <span class="switch prestashop-switch fixed-width-lg">
        <input type="radio" name="nitro_index_cms" id="nitro_index_cms_on" value="1" {if $nitro_index_cms}checked{/if}>
        <label for="nitro_index_cms_on">{l s='Yes' d='Admin.Global'}</label>
        <input type="radio" name="nitro_index_cms" id="nitro_index_cms_off" value="0" {if !$nitro_index_cms}checked{/if}>
        <label for="nitro_index_cms_off">{l s='No' d='Admin.Global'}</label>
        <a class="slide-button btn"></a>
      </span>
      <p class="help-block">
        {l s='Pages such as delivery and terms appear in results alongside products. They use the same allowance as products, and products always come first.' d='Modules.Nitrosearch.Admin'}
      </p>
    </div>

    <div class="form-group">
      <label>{l s='Use NitroSearch on the search results page' d='Modules.Nitrosearch.Admin'}</label>
      <span class="switch prestashop-switch fixed-width-lg">
        <input type="radio" name="nitro_results_takeover" id="nitro_results_on" value="1" {if $nitro_results_takeover}checked{/if}>
        <label for="nitro_results_on">{l s='Yes' d='Admin.Global'}</label>
        <input type="radio" name="nitro_results_takeover" id="nitro_results_off" value="0" {if !$nitro_results_takeover}checked{/if}>
        <label for="nitro_results_off">{l s='No' d='Admin.Global'}</label>
        <a class="slide-button btn"></a>
      </span>
      <p class="help-block">
        {l s='Off means the instant dropdown still works, but your theme keeps rendering the full results page.' d='Modules.Nitrosearch.Admin'}
      </p>
    </div>

    <div class="form-group">
      <label>{l s='Replace my theme’s own search suggestions' d='Modules.Nitrosearch.Admin'}</label>
      <span class="switch prestashop-switch fixed-width-lg">
        <input type="radio" name="nitro_suppress_native" id="nitro_suppress_on" value="1" {if $nitro_suppress_native}checked{/if}>
        <label for="nitro_suppress_on">{l s='Yes' d='Admin.Global'}</label>
        <input type="radio" name="nitro_suppress_native" id="nitro_suppress_off" value="0" {if !$nitro_suppress_native}checked{/if}>
        <label for="nitro_suppress_off">{l s='No' d='Admin.Global'}</label>
        <a class="slide-button btn"></a>
      </span>
      <p class="help-block">
        {l s='PrestaShop’s own search box shows a suggestions list of its own. With this on, it steps aside once ours has appeared — so shoppers never see two lists at once. If ours ever fails to load, the theme’s own list is left alone rather than removed.' d='Modules.Nitrosearch.Admin'}
      </p>
    </div>

    <div class="form-group">
      <label>{l s='Share anonymous search data' d='Modules.Nitrosearch.Admin'}</label>
      <span class="switch prestashop-switch fixed-width-lg">
        <input type="radio" name="nitro_share_search_data" id="nitro_share_on" value="1" {if $nitro_share_search_data}checked{/if}>
        <label for="nitro_share_on">{l s='Yes' d='Admin.Global'}</label>
        <input type="radio" name="nitro_share_search_data" id="nitro_share_off" value="0" {if !$nitro_share_search_data}checked{/if}>
        <label for="nitro_share_off">{l s='No' d='Admin.Global'}</label>
        <a class="slide-button btn"></a>
      </span>
      <p class="help-block">
        {l s='What shoppers searched for and which results they opened. No personal data and no cookies. Turning this off does not affect search itself.' d='Modules.Nitrosearch.Admin'}
      </p>
    </div>

    <div class="form-group">
      <label>{l s='Show “Powered by NitroSearch”' d='Modules.Nitrosearch.Admin'}</label>
      <span class="switch prestashop-switch fixed-width-lg">
        <input type="radio" name="nitro_show_badge" id="nitro_badge_on" value="1" {if $nitro_show_badge}checked{/if}>
        <label for="nitro_badge_on">{l s='Yes' d='Admin.Global'}</label>
        <input type="radio" name="nitro_show_badge" id="nitro_badge_off" value="0" {if !$nitro_show_badge}checked{/if}>
        <label for="nitro_badge_off">{l s='No' d='Admin.Global'}</label>
        <a class="slide-button btn"></a>
      </span>
    </div>

    <hr>
    <h4>{l s='Appearance' d='Modules.Nitrosearch.Admin'}</h4>
    <p class="help-block">
      {l s='These change how the search panel looks on your storefront. Nothing here is sent to your shoppers as a preset name — the choices are resolved to plain style values before the page is built.' d='Modules.Nitrosearch.Admin'}
    </p>

    <div class="form-group">
      <label>{l s='Density' d='Modules.Nitrosearch.Admin'}</label>
      <select name="nitro_design_look" class="form-control fixed-width-xl">
        <option value="roomy" {if $nitro_design_look == 'roomy'}selected{/if}>{l s='Roomy — bigger thumbnails, two-line names' d='Modules.Nitrosearch.Admin'}</option>
        <option value="compact" {if $nitro_design_look == 'compact'}selected{/if}>{l s='Compact — more results before scrolling' d='Modules.Nitrosearch.Admin'}</option>
        <option value="images" {if $nitro_design_look == 'images'}selected{/if}>{l s='Image-led — large pictures' d='Modules.Nitrosearch.Admin'}</option>
        <option value="text" {if $nitro_design_look == 'text'}selected{/if}>{l s='Text only — no thumbnails' d='Modules.Nitrosearch.Admin'}</option>
      </select>
    </div>

    <div class="form-group">
      <label>{l s='Colours' d='Modules.Nitrosearch.Admin'}</label>
      <select name="nitro_design_scheme" class="form-control fixed-width-xl">
        <option value="light" {if $nitro_design_scheme == 'light'}selected{/if}>{l s='Light' d='Modules.Nitrosearch.Admin'}</option>
        <option value="dark" {if $nitro_design_scheme == 'dark'}selected{/if}>{l s='Dark' d='Modules.Nitrosearch.Admin'}</option>
        <option value="auto" {if $nitro_design_scheme == 'auto'}selected{/if}>{l s='Match the shopper’s device' d='Modules.Nitrosearch.Admin'}</option>
      </select>
    </div>

    <div class="form-group">
      <label>{l s='Corners' d='Modules.Nitrosearch.Admin'}</label>
      <select name="nitro_design_corners" class="form-control fixed-width-xl">
        <option value="rounded" {if $nitro_design_corners == 'rounded'}selected{/if}>{l s='Rounded' d='Modules.Nitrosearch.Admin'}</option>
        <option value="soft" {if $nitro_design_corners == 'soft'}selected{/if}>{l s='Slightly rounded' d='Modules.Nitrosearch.Admin'}</option>
        <option value="square" {if $nitro_design_corners == 'square'}selected{/if}>{l s='Square' d='Modules.Nitrosearch.Admin'}</option>
      </select>
    </div>

    <div class="form-group">
      <label>{l s='Accent colour' d='Modules.Nitrosearch.Admin'}</label>
      <input type="text" name="nitro_design_accent" class="form-control fixed-width-lg"
             value="{$nitro_design_accent|escape:'html':'UTF-8'}" placeholder="#2563eb">
      <p class="help-block">{l s='A hex colour such as #2563eb. Leave empty to use the default.' d='Modules.Nitrosearch.Admin'}</p>
    </div>

    <div class="form-group">
      <label>{l s='Panel width' d='Modules.Nitrosearch.Admin'}</label>
      <select name="nitro_design_width" class="form-control fixed-width-xl">
        <option value="auto" {if $nitro_design_width == 'auto'}selected{/if}>{l s='Automatic' d='Modules.Nitrosearch.Admin'}</option>
        <option value="match" {if $nitro_design_width == 'match'}selected{/if}>{l s='Match the search box' d='Modules.Nitrosearch.Admin'}</option>
        <option value="wide" {if $nitro_design_width == 'wide'}selected{/if}>{l s='Wide' d='Modules.Nitrosearch.Admin'}</option>
      </select>
    </div>

    <div class="form-group">
      <label>{l s='Results in the dropdown' d='Modules.Nitrosearch.Admin'}</label>
      <input type="number" name="nitro_design_per_page" class="form-control fixed-width-sm"
             min="2" max="20" value="{$nitro_design_per_page|intval}">
    </div>

    <div class="form-group">
      <label>{l s='Filters' d='Modules.Nitrosearch.Admin'}</label>
      <select name="nitro_design_filters" class="form-control fixed-width-xl">
        <option value="auto" {if $nitro_design_filters == 'auto'}selected{/if}>{l s='Automatic' d='Modules.Nitrosearch.Admin'}</option>
        <option value="top" {if $nitro_design_filters == 'top'}selected{/if}>{l s='Across the top' d='Modules.Nitrosearch.Admin'}</option>
        <option value="off" {if $nitro_design_filters == 'off'}selected{/if}>{l s='Hidden' d='Modules.Nitrosearch.Admin'}</option>
      </select>
    </div>

    <hr>

    <div class="form-group">
      <label>{l s='Search box (advanced)' d='Modules.Nitrosearch.Admin'}</label>
      <input type="text" name="nitro_selector" class="form-control"
             value="{$nitro_selector|escape:'html':'UTF-8'}"
             placeholder="#search_widget input[name=&quot;s&quot;]">
      <p class="help-block">
        {l s='Leave empty unless your theme’s search box is not being picked up. A CSS selector pointing at it will override how we find it.' d='Modules.Nitrosearch.Admin'}
      </p>
    </div>

    <div class="form-group">
      <label>{l s='NitroSearch address' d='Modules.Nitrosearch.Admin'}</label>
      <input type="text" name="nitro_api_url" class="form-control"
             value="{$nitro_api_url|escape:'html':'UTF-8'}">
    </div>

    <div class="panel-footer">
      <button type="submit" name="submitNitroSettings" class="btn btn-default pull-right">
        <i class="process-icon-save"></i> {l s='Save' d='Admin.Actions'}
      </button>
    </div>
  </form>
</div>
