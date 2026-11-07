# User guide — Supertext Translation for Magento

For shop editors. Once an administrator has set up the module (see [INSTALLATION.md](INSTALLATION.md)), you translate products, categories, CMS pages and blocks from the Magento admin. Supertext writes the translation into your store views; you review it like any other edit.

The module's screens follow your admin interface language (English, German, French or Italian; *Account Setting → Interface Locale*).

## Try it on the demo

The Supertext Magento demo (ask Supertext for the address and an admin login) has two English sample products, the category *Swiss chocolate*, the CMS page *Delivery and returns* and the block *Chocolate promotion*, with store views for German (Switzerland), French (Switzerland) and Italian (Switzerland). Translate them as described below, then switch the store view in the shop's language switcher.

## How Magento stores translations

- **Products and categories** have one set of *default values* (scope *All Store Views*) and, per store view, their own values where needed. Supertext translates the default values and saves them as the store view's own values, which show up in that store view right away. The URL key follows the translated name.
- **CMS pages and blocks** have no store-view values. Supertext makes a **copy for each store view**, with the same URL key or identifier and the translated texts. A page's original is then no longer shown in those store views (Magento allows one page per URL and store view); a block's original keeps its store views, because Magento prefers the store view's own block.

## Translate products from the list

*Screenshots: Mage-OS 3.5 with the demo content.*

1. Open **Catalog → Products** and select the product(s) you want to translate.
2. Open **Actions** and choose **Translate with Supertext**.

   ![Catalog → Products with the products Dark chocolate praline box and Milk chocolate bar with hazelnuts selected, and the Actions menu open with Translate with Supertext at the bottom](images/products-mass-action.png)

3. The **Translate with Supertext** page lists the products with a column per store view showing how far each is translated already. Tick the store views to translate into: all store views in another language than the default values are ticked. A store view marked *No Supertext language yet* can't be ticked: your administrator hasn't chosen which Supertext language it is translated into (see the [installation guide](INSTALLATION.md#supertext-language-and-tone)).

   <img src="images/translate-page.png" alt="The Translate with Supertext page for two products: translate from the default values (All Store Views, en_US) into Deutsch (Schweiz) de-CH, Français (Suisse) fr-CH and Italiano (Svizzera) it-CH, all ticked, English not ticked; Overwrite existing translations is off; both products are Not translated in every store view" width="720">

4. Click **Translate**. Each product and store view takes a few seconds; the table shows the result as it goes, and a summary when everything is done.

   <img src="images/translate-results.png" alt="After translating: Dark chocolate praline box and Milk chocolate bar with hazelnuts translated (6 fields) in all three store views, and the summary Done: 6 translated, 0 unchanged, 0 failed." width="720">

To see the result, open the product and choose the store view in the scope switcher at the top: the translated fields show the store view's own value (*Use Default Value* is off).

<img src="images/translated-product.png" alt="The product in the store view Deutsch (Schweiz): name Pralinenbox mit dunkler Schokolade, Use Default Value unticked, and the Translate with Supertext button in the header" width="720">

## Translate from an edit page

Products, categories, pages and blocks have a **Translate with Supertext** button at the top of their edit page. It translates the **saved** item, so save your changes first. Categories have no list with mass actions: open the category in *Catalog → Categories* and use the button.

![The edit page of the category Swiss chocolate with the Translate with Supertext button next to Delete and Save](images/category-button.png)

## Translate CMS pages and blocks

**Content → Elements → Pages** and **Content → Elements → Blocks** have the same **Translate with Supertext** action in **Actions**. For each store view, the result shows *Created* (a new copy) with an **Open** link, or *Translated* when an existing copy was updated.

<img src="images/page-results.png" alt="Translate with Supertext for the page Delivery and returns: Created (6 fields) with an Open link in Deutsch (Schweiz), Français (Suisse) and Italiano (Svizzera), each with the note The original page is no longer shown in this store view; the translation replaces it there." width="720">

The page list then shows the original and its copies, each with its store view:

<img src="images/pages-translated.png" alt="Content → Pages filtered for delivery-and-returns: Delivery and returns (English), Lieferung und Rückgabe (Deutsch (Schweiz)), Livraison et retours (Français (Suisse)) and Consegna e resi (Italiano (Svizzera)), all with the URL key delivery-and-returns" width="720">

Whether new copies are enabled right away is a setting (*Same as the original*, or *Disabled* for review first). Translating the original again updates its copies; you can also edit a copy by hand like any page.

## Existing translations

Without any option, the module only fills fields that are **empty or still show the default value** in the store view. Fields with their own translation are kept, and the page says so: *Translated (5 fields) · 1 kept*, or *Already translated, unchanged* when there was nothing left to do. The store view columns show *Not translated*, *Partly translated* or *Translated* before you start.

To replace existing translations, tick **Overwrite existing translations**. The page warns you first: changes made by hand in those store views are lost.

<img src="images/overwrite-warning.png" alt="Translate with Supertext for the category Swiss chocolate, already translated into Deutsch (Schweiz): Overwrite existing translations is ticked and a warning explains that fields that are already translated will be replaced and changes made by hand are lost" width="720">

## Export for translation and import the translated file

Instead of translating with Supertext AI right away, you can export the texts as a file, have them translated elsewhere, and import the translated file. Both are at the bottom of the *Translate with Supertext* page (open it from the list's **Actions** or an edit page's button, as for translating).

**Export for translation**

1. Tick the store views to translate into (above, as for translating) and, if needed, **Overwrite existing translations** (without it, fields that already have a translation in a store view are left out, as when translating).
2. Under **Export for translation**, choose the **File format**:
   - **XLIFF 1.2 (for translation tools)**: the better choice for translators and translation tools (Trados, memoQ, Phrase, …): formatting appears as protected tags, and one file holds all selected items and store views.
   - **HTML (one language per file)**: one item in one store view; it can be uploaded to Supertext's web app or translated by anyone who works with HTML. Translators must leave the file's head (the `supertext:` lines) and the `data-st-id` attributes alone. An HTML file can't mark a text as untranslated: imported unchanged, it writes the source text into the store view.
3. Click **Download file**. The file is named like `magento-product-42-store-2.xlf`. *Nothing to translate.* means every field is empty or already translated in the chosen store views. Leave `{{…}}` directives in the texts as they are.
4. Send the file to your translator, open it in your translation tool, or upload it at supertext.com.

**Import translation**

1. Open the *Translate with Supertext* page (any item; the file itself says which items and store views it belongs to) and, under **Import translation**, choose the file under **Translated file (XLIFF or HTML)**. Options (both off by default):
   - **Also import texts whose source changed since the export**: a text that was edited in the default values after the export is skipped unless you tick this.
   - **Overwrite existing translations**: a field that got a translation in the store view since the export is kept unless you tick this.
2. Click **Import translation**. The texts are saved exactly as when translating with Supertext: store-view values for products and categories (URL key included), a copy per store view for pages and blocks.
3. The page shows a summary per item and store view, with an **Open** link:

| Line | Meaning |
| --- | --- |
| Imported: 3 | Saved in the store view. |
| Skipped, source changed since the export: 1 | The default value was edited after the export. Export again, or import with *Also import texts whose source changed since the export*. |
| Not translated in the file: 1 | The file has no translation for this text. |
| Formatting changed, not imported: 1 | The translation breaks or adds formatting (tags) the source doesn't have. Fix it in the file and import again. |
| Not in the file: 1 | The field isn't in the file, e.g. one filled after the export. |
| Kept, already translated in this store view: 1 | The field got a translation since the export; tick *Overwrite existing translations* to replace it. |

Errors: *This file isn't a valid XLIFF or HTML translation file.*, *This file wasn't exported by the Supertext module, or its header was removed.*, *The file is too large (at most 10 MB).*, *This file belongs to another system (…)* (exported by another Supertext plugin), *The item this file was exported from no longer exists.*, *The shop has no store view …*, *You are not allowed to edit this item.* Nothing is saved for an item with an error.

## What is translated

| Item | Fields |
| --- | --- |
| Products | Name, short description, description, meta title, meta keywords, meta description; the URL key follows the name |
| Categories | Name, description, meta title, meta keywords, meta description; the URL key follows the name |
| CMS pages | Title, content heading, content, meta title, meta keywords, meta description (the URL key stays the same) |
| CMS blocks | Title, content (the identifier stays the same) |

- Rich text (descriptions, page and block content) keeps its formatting, links and Page Builder layout. Magento directives such as `{{widget …}}`, `{{store url=…}}` or `{{media url=…}}` are left untouched.
- The **URL key** of a product or category follows the translated name while the store view still uses the default URL key, or when you overwrite. A URL key you set by hand is kept. The old URL redirects to the new one.
- **Not translated** (yet): product attributes other than the ones above (e.g. custom text attributes, dropdown option labels), image labels, custom options, reviews, widgets' own settings, emails and the theme's texts (use Magento's translation CSV files or inline translation for those).

## Review

Products and categories: open the item, switch the scope to the store view and read the fields; the translation is live as soon as it is saved. Pages and blocks: open the copy (**Open** in the result, or the list). To check in the shop, switch the store view in the shop's language switcher.

**Translation review:** if your administrator turned on *Translation review* for a store view, reviewers (your colleagues or Supertext linguists) check and correct the translations on the storefront. Their corrections come into Magento the next time you translate the items into that store view: after a review, translate them again with **Overwrite existing translations**. Texts reviewed once keep their corrected wording in later translations.

## When something goes wrong

The table shows the reason for each item and store view that fails; the other ones still run.

| Message | What to do |
| --- | --- |
| *Supertext is not set up yet* | An administrator needs to enter the API key in the configuration. |
| *You are not allowed to edit this item* | Your role can't edit this kind of item; ask an administrator. |
| *Authentication failed* | The API key is no longer valid; tell your administrator. |
| *URL key "…" is already used in this store view* | The texts are translated; give the item a different URL key by hand in that store view. |
| *The original is assigned only to this store view* | The page or block belongs to that store view already; assign it to the source language's store views first. |
| *Too many requests to Supertext* | Wait a moment and click **Translate** again. Items already done show *Already translated, unchanged*. |
| *Your Supertext translation limit is exceeded* | Your Supertext plan's volume is used up; tell your administrator. |
| *Timed out waiting for the Supertext translation* | Very long texts. Try again, or ask your administrator to raise the timeout. |
| *The language "…" has no Supertext language yet* | Your administrator hasn't chosen the Supertext language for that store view (*Stores → Configuration → Supertext → Translation → Language*, in the store view's scope). Ask them to; the other store views are translated. |
| *The source language "…" (the default locale) has no Supertext language yet* | Supertext doesn't know the language of the default values yet; ask your administrator to set it. |
| *Supertext does not translate from "…" into "…"* | Supertext doesn't offer that store view's Supertext language; ask your administrator to choose another one. |
| *Supertext offers no formal or informal tone for this language* | The tone set for this store view isn't available at Supertext. Ask your administrator to set it to *Supertext default*. |
| *The Supertext translation came back incomplete* | Nothing was saved for that item and store view. Click **Translate** again. |

## Support

Questions, problems or ideas: use the [Supertext support form](https://integrations.supertext.com/en/support?platform=magento). Mention the version of Magento and of the plugin, what you did and what you expected; an error message helps a lot. You get an answer by email, in your language.
