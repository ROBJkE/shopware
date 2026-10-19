---
title: Compile the storefront stylesheet with Webpack and Bootstrap 6
issue: #21751
author: Robert
author_github: @ROBJkE
---
# Storefront
* Changed theme compilation to copy the Webpack stylesheet `dist/css/storefront.css` to `theme/{prefix}/css/all.css`
* Changed storefront markup, SCSS, and JavaScript to Bootstrap 6 class and component names
___
# Upgrade Information
## Webpack storefront stylesheet
Theme compilation no longer compiles SCSS.
Run the storefront build before `theme:compile`, and update storefront overrides to Bootstrap 6 names.
See `UPGRADE-6.9.md`.
___
# Next Major Version Changes

## Webpack storefront stylesheet

Theme compilation compiles no SCSS.
The stylesheet comes from the storefront Webpack build.
Plugin `@Plugins` style files are no longer compiled.
Update storefront overrides to Bootstrap 6 names before this version.

## Bootstrap 6 storefront classes

Storefront classes and JavaScript use Bootstrap 6 names.
There is no Bootstrap 5 class shim.
Replace `d-md-none` with `md:d-none`, `btn-primary` with `btn-solid theme-primary`, `modal` with `dialog`, `offcanvas` with `drawer`, and `dropdown-menu` with `menu`.
