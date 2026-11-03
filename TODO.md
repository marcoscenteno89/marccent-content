# To do

Relation fields, a Loop's `where` on posts and the `count` / `sum` / `avg` / `min` / `max` tokens are committed,
tested on the crm-sample site (58 checks). 0.2.0 (2026-11-02) added the field rules, the new field types, groups on
terms, users and templates, the Loop's paging, filters and search, and Show if.

- [ ] Peer review, then commit and release 0.2.0 (a new field type; the Loop's `relation` attribute and `where` on
      posts; the Loop's current post is its context's, so Loops nest; the Loop skips WordPress's unused render of its
      item, which doubled with each nested Loop; a token inside a URL is URL-encoded; Fields → Field groups is
      WordPress's list table, a group's editor has a field per accordion).
- [ ] **On delete** (Django's `on_delete`): deleting a post leaves the posts pointing to it with a broken key (hidden,
      not cleaned). Per relation: cascade, protect (refuse the delete), set null.
- [ ] **Aggregates cost a query each.** A grid of 20 accounts with 3 totals is 60 queries per view. Cache per request,
      or prime one grouped query per Loop, before using them on big lists.
- [ ] **Computed values**: no `quantity × unit_price`; a line total or an order total has to be stored.
- [ ] **`!=` on a many-to-many** is approximate: `products!=12` still passes a work order with 12 and another product
      (needs a `NOT EXISTS` subquery).
- [ ] **Sort or filter by an aggregate** ("accounts with the most work orders first", "accounts with no contacts").
- [x] **Required and unique fields** (a SKU, an email). Checked when the editor saves (REST); a post saved some other
      way (code, an import) isn't checked.

From the crm-sample stress test (`sites/crm-sample/NOTES.md` in the wp repo):

- [ ] **Site-wide counts and sums** (`count:account`, `sum:work_order.total`): a dashboard's headline numbers.
- [x] **A where for an empty field** (`parent_account=`; `parent_account!=` for not empty).
- [x] **Show if**: hide a block (or a paragraph of tokens) when a value is empty, or unless a where passes.
- [ ] **A page per record from a normal page**: a Loop taking its current post from the address (`?account=12`).
- [x] **Paging** for a Loop (next / previous), and **visitors' filters** (a search box, a status picker).
- [ ] **A map picker** for a location field: today the address is looked up (OpenStreetMap), or the latitude and
      longitude typed; no clicking on a map.
- [ ] **Repeating groups inside groups** (ACF's nested repeaters, flexible content).
- [ ] **Group by** a field: one Loop for the board, its items in a heading per status.
- [ ] **Sums across rows of many posts** (a product's quantity on every work order's line items).
- [ ] **The editor's preview** of a related Loop (the related posts, not the type's first) and of counts and sums.
- [ ] **A Loop's items as a Carousel's slides** (with Marccent Blocks: an "items only" Loop, or the Carousel reading
      the Loop's items).

## Notes

- Speed pass, not committed: a Loop's related posts, a post's relations and an aggregate's posts each primed in one
  query; one posts query per Loop (no split without an object cache); editor data built only in the admin.
- **Nested Loops cost a query per outer item.** One query per level, the children grouped by parent.
- **A Loop's relations are guessed from its item's markup** (`mcc_prime_related()`): a relation's name in the text
  without being used costs one wasted query per Loop. Read the tokens instead, if it shows up.
