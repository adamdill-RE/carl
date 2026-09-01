<?php
/**
 * The Carl mark: a C with a two-leaf seedling in its opening (Claude Design,
 * handoff Section 13.5).
 *
 * INLINE, and not an <img>, on purpose. Every path is currentColor, which is
 * what lets one drawing serve the white-on-brand topbar and the green login
 * page; an <img> cannot inherit currentColor and would cost a second file to
 * keep in sync. Inline SVG is markup rather than a resource load, so the CSP
 * (style-src 'self') is untouched -- but note that means NO style attribute
 * may ever appear in here, because that same CSP drops it silently.
 *
 * Sized by .brand-mark in carl.css. No width/height here.
 */
?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" role="img" fill="none">
  <title>Carl</title>
  <path d="M23.07 7.57A11 11 0 1 0 23.07 24.43" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
  <path d="M16 24V13" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"></path>
  <path d="M16 18.2C11.4 18.6 9.1 16 9.5 12.5C13.4 12.8 16 15 16 18.2Z" fill="currentColor"></path>
  <path d="M16 18.2C20.6 18.6 22.9 16 22.5 12.5C18.6 12.8 16 15 16 18.2Z" fill="currentColor"></path>
</svg>