# Rendering verification — 2026-11-03

Tested version 1.3.3 on staging.beltoft.net, checkout and its native Bricks guest-account block. All browser checks passed at 1440, 767, 600 and 390 px, including the element's native 300 px width cap and a resize to 320 px. Left alignment is owned by the native Bricks parent block; the suite supports both alignments.

- Google's temporary button stays transparent and inert until the loaded iframe replaces it and page fonts settle.
- The reserved height stays constant throughout the swap; the button lines up with the email field without horizontal overflow.
- Closing and reopening a checkout step at the same width reuses its iframe. Resizing redraws at the new width.
- Small and medium sizes retain their own reserved space while allowing Google's larger iframe click area.
- Blocking either Google's SDK or its button iframe shows the existing visible error. Offscreen buttons do not time out while Google delays them.

JavaScript/PHP syntax checks and Plugin Check passed with zero errors (five existing warnings). No Google credential exchange, account creation, checkout submission, payment or provisioning was performed. Native layout changes belong to the site's checkout page and are recorded in its separate repository.

Twelve Bricks registration, controls and authorized editing checks also passed. Before the fix, an administrator's native element-update dry run was denied with `edit_element_sgl-google-login`; after fixing registration timing, the same permission-checked request succeeds. Repeated registration preserves loaded controls, and native width, max-width and padding target the element root. No permissions, roles or AI ability gates were changed.
