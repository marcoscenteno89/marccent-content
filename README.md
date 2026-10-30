# Marccent Content

Custom post types, their fields and loops for Marccent sites, with no build step. Requires [Marccent Blocks](https://github.com/marcoscenteno89/marccent-blocks) (WordPress won't activate it without): its blocks and the Marccent theme's styles and scripts come from there, one shared copy at one version, so this plugin bundles none of it.

- **Types and fields** from one JSON spec (Settings → Content, or the `marccent_content_spec` filter): post types, taxonomies, and fields of type string, text, number, url, email, date, boolean, image or select. Fields can go on existing types (post, page) too, and are edited in a Fields panel beside each post.
- **Loop block**: a query (type, count, order, also by a field, terms, the pages under a parent) whose item you design once with any blocks; it repeats in the theme's grid.
- **The post's values in the item**: the Field block (with formats: currency, number, date, words…), `{{ tokens }}` in any block (`{{ title }}`, `{{ price | currency }}`, `{{ image:medium }}`, a button linking to `{{link}}`), or block bindings with the `marccent/field` source.

```json
{
  "currency": "$",
  "types": {
    "project": {
      "label": "Projects", "singular": "Project", "slug": "projects",
      "taxonomies": { "project_service": { "label": "Services" } },
      "fields": { "city": { "type": "string" }, "price": { "type": "number" }, "before": { "type": "image" } }
    }
  }
}
```

Works with [Marccent Blocks](https://github.com/marcoscenteno89/marccent-blocks). Requires WordPress 6.9+.

## Uninstalling

Deleting the plugin removes its settings (the content types' spec). The posts of those types, their terms and fields
stay: they're the site's content, and they're back as they were if the plugin is installed again with the same spec.
