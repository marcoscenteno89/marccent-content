# To do: marccent-content

The rules for this list are in [PRODUCT-LINE.md §8](https://github.com/marcoscenteno89/marccent-theme/blob/master/PRODUCT-LINE.md#8-work-lists-ticketsmd-and-each-projects-todomd):
work fixed here only; anything the theme fixes is a ticket in marccent-theme's
[TICKETS.md](https://github.com/marcoscenteno89/marccent-theme/blob/master/TICKETS.md), linked under Waiting on.

## Bugs

- [ ] **`!=` on a many-to-many** is approximate: `products!=12` still passes a work order with 12 and another product
      (needs a `NOT EXISTS` subquery). (found 2026-10-31)
- [ ] **The editor's preview** of a related Loop (the related posts, not the type's first) and of counts and sums. (found 2026-10-31)
- [ ] **A private field** (a flag on a field): never printed by tokens, the Field block, Loops or the JSON-LD, only edited in wp-admin (crm-sample's annual revenue). People who edit pages can print any published post's fields today. (found 2026-11-05)

## Requests

- [ ] **Peer review, then commit and release** — written for 0.2.0 (a new field type; the Loop's `relation` attribute and `where` on
      posts; the Loop's current post is its context's, so Loops nest; the Loop skips WordPress's unused render of its
      item, which doubled with each nested Loop; a token inside a URL is URL-encoded; Fields → Field groups is
      WordPress's list table, a group's editor has a field per accordion).
- [ ] **On delete** (Django's `on_delete`) — set null done 2026-11-09 (a post deleted for good leaves no relation pointing to it, in groups that don't repeat); left for after 1.0: deleting a post leaves the posts pointing to it with a broken key (hidden,
      not cleaned). Per relation: cascade, protect (refuse the delete), set null.
- [ ] **Aggregates cost a query each.** A grid of 20 accounts with 3 totals is 60 queries per view. Cache per request,
      or prime one grouped query per Loop, before using them on big lists. (found 2026-10-31)
- [ ] **A Loop's items as a Carousel's slides** (with Marccent Blocks: an "items only" Loop, or the Carousel reading
      the Loop's items). (found 2026-10-31)
- [ ] **A site group's schema** (options): the Organization from Site content's fields on a site without Marccent Local (its logo, social links, phone), instead of the site's name and tagline only. (found 2026-11-05)
- [ ] **Check every mapped type with Google's Rich Results Test** once a site is live (FAQ, breadcrumbs, LocalBusiness). (found 2026-11-05)
- [x] **Required and unique fields** (a SKU, an email). Checked when the editor saves (REST); a post saved some other
      way (code, an import) isn't checked. (found 2026-11-02)
- [x] **A where for an empty field** (`parent_account=`; `parent_account!=` for not empty). (found 2026-11-02)
- [x] **Show if**: hide a block (or a paragraph of tokens) when a value is empty, or unless a where passes. (found 2026-11-02)
- [x] **Paging** for a Loop (next / previous), and **visitors' filters** (a search box, a status picker). (found 2026-11-02)
- [x] **A JSON field type** (Data: any names and values in one field): for a form's answers, whose fields differ per
      form, and an API's raw item. Saved as JSON text with its texts cleaned, typed in the editor as JSON (checked as
      it's typed) with a table of its values; `{{ data }}`, `{{ data:email }}`, `{{ data:address.city }}`; the Field
      block shows it as a list. Done 2026-11-05. Not yet: a Loop's where or Show if on a value inside it on posts
      (`data:city=Rexburg` reads it per item, not in the query). (found 2026-11-05)
- [x] **Private post types** ("Only in wp-admin" on Fields → Post types): no pages, archive, search or menus; for a
      form's entries. Done 2026-11-05. Not yet: who may read them (today WordPress's usual rules for private posts:
      editors and admins). (found 2026-11-05)
- [x] **An outer item's values in a Loop's where** (`state={{ code }}`): a nested Loop can only follow a relation field
      today, so cities can't be listed under a state that is only a field on them. (found 2026-11-04)
- [x] **marccent-local's area directory as Loops** — done 2026-11-05: rc-imp's Service Areas is a page of nested
      Loops (regions, then each one's cities) and the `city` type is the site's (Fields → Post types). (found 2026-11-04)

## Waiting on

- [ ] **[T-022](https://github.com/marcoscenteno89/marccent-theme/blob/master/TICKETS.md)** — the content admin is hand-built (`assets/admin.js`, `editor.js`) (found 2026-11-08)

## After 1.0

Found in the crm-sample stress test (`sites/crm-sample/NOTES.md` in the wp repo) and comparing with Webflow's CMS
(2026-11-04).

- [ ] **On delete** (Django's `on_delete`): deleting a post leaves the posts pointing to it with a broken key (hidden,
      not cleaned). Per relation: cascade, protect (refuse the delete), set null. (found 2026-10-31)
- [ ] **Computed values**: no `quantity × unit_price`; a line total or an order total has to be stored. (found 2026-10-31)
- [ ] **Sort or filter by an aggregate** ("accounts with the most work orders first", "accounts with no contacts"). (found 2026-10-31)
- [ ] **Site-wide counts and sums** (`count:account`, `sum:work_order.total`): a dashboard's headline numbers. (found 2026-10-31)
- [ ] **A page per record from a normal page**: a Loop taking its current post from the address (`?account=12`). (found 2026-10-31)
- [ ] **A map picker** for a location field: today the address is looked up (OpenStreetMap), or the latitude and
      longitude typed; no clicking on a map. (found 2026-11-02)
- [ ] **Repeating groups inside groups** (ACF's nested repeaters, flexible content). (found 2026-11-02)
- [ ] **Group by** a field: one Loop for the board, its items in a heading per status. (found 2026-10-31)
- [ ] **Sums across rows of many posts** (a product's quantity on every work order's line items). (found 2026-10-31)
- [ ] **SEO and social image from fields** — a type's SEO title, description and OG image as tokens
      (`{{ title }} in {{ city }}`), handed to Rank Math. (found 2026-11-04)
- [ ] **Curated Loop** — pick and order the items by hand (Webflow's curated lists: "featured products"). (found 2026-11-04)
- [ ] **Previous / next item** on a post's page, in the Loop's order. (found 2026-11-04)
- [ ] **CSV import and export of a type's posts or a group's rows** (Webflow's bulk CSV). (found 2026-11-04)
- [ ] **Admin columns and filters** from a type's fields (Django's list_display / list_filter / search_fields). (found 2026-11-04)
- [ ] **Item status: draft, scheduled, archived** for group rows (posts have it; rows don't). (found 2026-11-04)
- [ ] **A front-end form that creates or edits a post of a type** (Django's ModelForm; Marccent forms save entries only). (found 2026-11-04)
- [ ] **A Schema box on Fields → Post types** (a type's `schema` is only in Fields → JSON today). (found 2026-11-05)
- [ ] **A site group's schema** (options): the Organization from Site content's fields (Marccent Local is gone; its logo, social links, phone), instead of the site's name and tagline only. (found 2026-11-05)
- [ ] **Check every mapped type with Google's Rich Results Test** once a site is live (FAQ, breadcrumbs, LocalBusiness). (found 2026-11-05)
- [ ] **A private field** (a flag on a field): never printed by tokens, the Field block, Loops or the JSON-LD, only edited in wp-admin (crm-sample's annual revenue). People who edit pages can print any published post's fields today. (found 2026-11-05)
- [ ] **Sources that need the account owner to log in once** (OAuth: Facebook page recommendations, Instagram, Google Business Profile's every review): a Connect step per source. (found 2026-11-05)
- [ ] **A Reviews field group and pattern** (name, rating, review, date, link, source; a Loop with the Rating stars block) for the Google reviews source, and rc-imp's Place ID and a Places server key to try it. No Review schema: a business's own reviews don't get stars. (found 2026-11-05)
- [x] **Loop: a row of content-width items** — done 2026-11-09: a Layout setting (equal columns, a row each item its own width, a row that wraps).
