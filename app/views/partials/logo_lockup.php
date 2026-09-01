<?php
/**
 * The mark plus a drawn "carl" wordmark, for login and the onboarding wizard
 * at 180-240px (Claude Design, handoff Section 13.5).
 *
 * NOT for the topbar: Claude Design's note is explicit that the HTML .brand
 * text belongs there instead, because it stays crisp at any zoom, respects
 * the user's text size and is selectable, none of which an 18px SVG wordmark
 * manages. The wordmark is paths, not <text>: the server has no fonts to
 * resolve one against.
 */
?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 114 32" role="img" fill="none">
  <title>Carl — the garden helper</title>
  <path d="M23.07 7.57A11 11 0 1 0 23.07 24.43" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
  <path d="M16 24V13" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"></path>
  <path d="M16 18.2C11.4 18.6 9.1 16 9.5 12.5C13.4 12.8 16 15 16 18.2Z" fill="currentColor"></path>
  <path d="M16 18.2C20.6 18.6 22.9 16 22.5 12.5C18.6 12.8 16 15 16 18.2Z" fill="currentColor"></path>
  <g stroke="currentColor" stroke-width="3.4" stroke-linecap="round">
    <path d="M56.02 11.77A7 7 0 1 0 56.02 23.23"></path>
    <circle cx="72.5" cy="17.5" r="7"></circle>
    <path d="M79.5 10.9V24.5"></path>
    <path d="M90 24.5V11.2"></path>
    <path d="M90 15.4C90 11.2 93.6 9.9 97.4 10.9"></path>
    <path d="M106 6.2V24.5"></path>
  </g>
</svg>