# Marccent Content

Field groups, custom post types and loops for Marccent sites, with no build step. Requires [Marccent Blocks](https://github.com/marcoscenteno89/marccent-blocks) (WordPress won't activate it without): its blocks and the Marccent theme's styles and scripts come from there, one shared copy at one version, so this plugin bundles none of it.

- **Field groups**, as in ACF, built under **Fields → Field groups** (no JSON needed): a named set of fields (an office: address, phone, lat, lng) put on post types, on some of their posts by slug, or on the whole site (edited under **Site content**, by anyone who edits pages). Field types: text, several lines, number, URL, email, date, yes/no, image, choice from a list, relation. A group that repeats is a list of rows, edited beside the post as rows to add, reorder and remove; things without a page of their own (a gallery's photos, offices, the team) need no post type.
- **Post types** and their taxonomies under **Fields → Post types**, for things that need a page each.
- Everything is kept in the database. **Fields → JSON** shows the same spec as JSON, and **Tools → Marccent export** (Marccent Blocks) exports and imports it with the site content.
- **Relations**, as Django's foreign keys and many-to-many fields: one field type, **Relation**, to the post type you pick (a contact's account), holding one post or several in an order (a work order's products). Each related post's id is a meta row of its own, so finding the posts that point to one is an indexed query, either way: a contact's `{{ account.phone }}`, an account's contacts. A related post that's deleted, a draft or password-protected doesn't show.
- **Loop block**: a query (type, count, order, also by a field, only the posts whose fields pass a where (`status=New,Scheduled&total>=1000&scheduled<today`), terms, the pages under a parent, **related** to the current post: those whose `account` is it, or its own `products`), or a repeating group's rows (from the current post, a page or the site, filtered by `service=flatwork` or `featured~home`), whose item you design once with any blocks; it repeats in the theme's grid. Loops nest: the current post of a Loop inside another is that Loop's item, so each level is related to the one around it (an account's work orders, each one's products, each product's other work orders…).
- **The values in the item**: the Field block (with formats: currency, number, date, words…), `{{ tokens }}` in any block (`{{ title }}`, `{{ price | currency }}`, `{{ image:medium }}`, a button linking to `{{link}}`), or block bindings with the `marccent/field` source. In a Loop of rows, the row's fields (`{{ caption }}`, `{{ image:alt }}`); anywhere, a group's field (`{{ office.phone }}`); through relations, as far as they go (`{{ account }}`: its title, `{{ contact.account.city }}`, `{{ products.price | currency }}`: each one's). Counts and sums, as Django's aggregates, with a where after a `?`: `{{ count:work_order.account?status=New }}` (the posts pointing to this one), `{{ sum:this.products.price | currency }}` (its own), `{{ sum:this.line_items.quantity }}` (a repeating group's rows); also `avg`, `min`, `max`.

```json
{
  "currency": "$",
  "types": {
    "project": {
      "label": "Projects", "singular": "Project", "slug": "projects",
      "taxonomies": { "project_service": { "label": "Services" } },
      "fields": { "city": { "type": "string" }, "price": { "type": "number" }, "before": { "type": "image" } }
    }
  },
  "groups": {
    "office": { "label": "Office", "on": [ "options" ], "fields": { "phone": {}, "lat": { "type": "number" }, "lng": { "type": "number" } } },
    "photos": { "label": "Photos", "repeat": true, "on": [ "page:gallery" ],
      "fields": { "image": { "type": "image" }, "caption": {}, "service": { "type": "select", "options": [ "flatwork", "curb" ] } } }
  }
}
```

Use a post type only when each item needs a page of its own (its URL, title, search meta); otherwise a group.

Works with [Marccent Blocks](https://github.com/marcoscenteno89/marccent-blocks). Requires WordPress 6.9+.

## Uninstalling

Deleting the plugin removes its settings (the content types' spec). The posts of those types, their terms, fields and
groups (the site's too, mcc_<group> options) stay: they're the site's content, and they're back as they were if the plugin is installed again with the same spec.
