# Working in marccent-content

This project is part of the Marccent product line. Its rules live in one place, [marccent-theme's PRODUCT-LINE.md](https://github.com/marcoscenteno89/marccent-theme/blob/master/PRODUCT-LINE.md) (in the workspace: `marccent-theme/PRODUCT-LINE.md`). Read it before any change;
don't copy its rules here. What follows is how to apply them, and it isn't optional.

1. **Feature freeze until 1.0 is live** (PRODUCT-LINE.md section 3). Only debugging, fixing and finishing what
   exists. A request for anything new (a feature, block, setting, component, option): say it's frozen, add it to
   `TODO.md` under After 1.0, and stop there unless the person confirms it's finishing existing work.
2. **The work list is `TODO.md`** and follows PRODUCT-LINE.md section 8 strictly: the template's five sections, one
   item shape with its found date, and work the theme fixes linked by ticket ID under Waiting on, never copied.
3. **Don't run on your own.** Do what was asked and nothing more. Anything else you find (a bug, a cleanup, a
   missing component) goes on the list and is named in your reply; it isn't fixed in the same change.
4. **No custom assets; adapters only translate** (section 4). When marccent-theme can't do something, add a ticket
   to marccent-theme's TICKETS.md and a Waiting on line here; never work around it with CSS, JS or a copied component.
5. **Every change keeps the lists in sync.** A change that ships a ticket removes its Waiting on lines and stopgaps in
   every project it names, in the same round. Before you finish, run marccent-theme's checks from the workspace:
   `npm run todos` and `npm run conformance` (both are in `npm test`).
