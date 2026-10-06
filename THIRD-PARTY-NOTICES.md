# Third-Party Notices

NoBuildCMS bundles or depends on the following open-source components. All are
redistributed in compliance with their licenses, which are all permissive and
compatible with this project's MIT license.

## Bundled (redistributed in this repository)

### Datastar
- File: `public/assets/datastar.js` (v1.0.4)
- License: MIT
- Copyright (c) Star Federation and Datastar contributors
- Source: https://github.com/starfederation/datastar

## Loaded at runtime via CDN (not redistributed)

### Tailwind CSS
- Loaded from `https://cdn.tailwindcss.com`
- License: MIT
- Copyright (c) Tailwind Labs, Inc.
- Source: https://github.com/tailwindlabs/tailwindcss

## Installed via Composer (not committed; see `composer.json`)

### Twig
- License: BSD-3-Clause
- Copyright (c) Fabien Potencier
- Source: https://github.com/twigphp/Twig

### symfony/polyfill-mbstring, symfony/polyfill-ctype, symfony/deprecation-contracts
- License: MIT
- Copyright (c) Fabien Potencier / Symfony contributors
- Source: https://github.com/symfony

---

To regenerate the full dependency license list after `composer install`, run:

```
composer licenses
```
