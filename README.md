# Marccent Content

Field groups, custom post types and loops for Marccent sites, with no build step. Requires [Marccent Blocks](https://github.com/marcoscenteno89/marccent-blocks) (WordPress won't activate it without): its blocks and the Marccent theme's styles and scripts come from there, one shared copy at one version, so this plugin bundles none of it.

- **Field groups**, as in ACF, built under **Fields → Field groups** (no JSON needed): a named set of fields (an office: address, phone, lat, lng) put on post types, on some of their posts by slug, or on the whole site (edited under **Site content**, by anyone who edits pages). Field types: text, several lines, number, URL, email, date, yes/no, image, choice from a list. A group that repeats is a list of rows, edited beside the post as rows to add, reorder and remove; things without a page of their own (a gallery's photos, offices, the team) need no post type.
- **Post types** and their taxonomies under **Fields → Post types**, for things that need a page each.
- Everything is kept in the database. **Fields → JSON** shows the same spec as JSON, and **Tools → Marccent export** (Marccent Blocks) exports and imports it with the site content.
- **Loop block**: a query (type, count, order, also by a field, terms, the pages under a parent), or a repeating group's rows (from the page being viewed, a page or the site, filtered by `service=flatwork` or `featured~home`), whose item you design once with any blocks; it repeats in the theme's grid.
- **The values in the item**: the Field block (with formats: currency, number, date, words…), `{{ tokens }}` in any block (`{{ title }}`, `{{ price | currency }}`, `{{ image:medium }}`, a button linking to `{{link}}`), or block bindings with the `marccent/field` source. In a Loop of rows, the row's fields (`{{ caption }}`, `{{ image:alt }}`); anywhere, a group's field (`{{ office.phone }}`).

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
