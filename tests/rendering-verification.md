# Rendering verification — 2026-11-03

Tested version 1.3.2 on staging.beltoft.net, checkout and its native Bricks guest-account block. All browser checks passed at 1440, 767, 600 and 390 px. The user's final choice is left alignment, owned by the native Bricks parent block; the suite now supports both alignments.

- Google's temporary button stays transparent and inert until the loaded iframe replaces it and page fonts settle.
- The reserved height stays constant throughout the swap; the button lines up with the email field without horizontal overflow.
- Closing and reopening a checkout step at the same width reuses its iframe. Resizing redraws at the new width.
- Small and medium sizes retain their own reserved space while allowing Google's larger iframe click area.
- Blocking either Google's SDK or its button iframe shows the existing visible error. Offscreen buttons do not time out while Google delays them.

JavaScript/PHP syntax checks and Plugin Check passed with zero errors (five existing warnings). No Google credential exchange, account creation, checkout submission, payment or provisioning was performed. Native layout changes belong to the site's checkout page and are recorded in its separate repository.
