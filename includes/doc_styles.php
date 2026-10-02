<?php
/**
 * FORMAL DOCUMENT STYLING
 *
 * Shared by the Employment Contract page and the Request Form document view,
 * so both read as the same kind of official paper document rather than as two
 * separately-styled screens.
 *
 * Included after includes/header.php. Guarded so a page that includes it twice
 * doesn't emit the stylesheet twice.
 */
if (!defined('DOC_STYLES_RENDERED')) {
    define('DOC_STYLES_RENDERED', true);
?>
<style>
/* --- The sheet of paper ------------------------------------------------- */
.doc-sheet {
  background: #fff;
  color: #1c1c1c;
  max-width: 900px;
  margin: 0 auto 2rem;
  padding: 3.25rem 3.5rem;
  border: 1px solid #ddd6ca;
  box-shadow: 0 2px 14px rgba(0, 0, 0, .07);
  font-family: Georgia, 'Times New Roman', serif;
  font-size: .95rem;
  line-height: 1.75;
}
.doc-justify { text-align: justify; }

/* --- Letterhead and title ----------------------------------------------- */
.doc-letterhead {
  text-align: center;
  border-bottom: 2px solid #1c1c1c;
  padding-bottom: 1.1rem;
  margin-bottom: 1.75rem;
}
.doc-letterhead .company {
  font-size: 1.35rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
}
.doc-letterhead .meta { font-size: .82rem; color: #555; }
.doc-title {
  text-align: center; font-size: 1.15rem; font-weight: 700;
  letter-spacing: .18em; text-transform: uppercase; margin: 1.5rem 0 .35rem;
}
.doc-subtitle {
  text-align: center; font-size: .85rem; color: #6b6257;
  font-family: 'Nunito', system-ui, sans-serif; margin-bottom: 1rem;
}

/* --- Reference bar (document number, dates, status) --------------------- */
.doc-refbar {
  display: flex; flex-wrap: wrap; justify-content: center; gap: .5rem 1.75rem;
  font-family: 'Nunito', system-ui, sans-serif; font-size: .82rem;
  border-top: 1px solid #e3ddd2; border-bottom: 1px solid #e3ddd2;
  padding: .6rem 0; margin-bottom: 2rem;
}
.doc-refbar-key { font-weight: 800; letter-spacing: .08em; }

/* --- Clauses and field tables ------------------------------------------- */
.doc-clause { margin-top: 1.5rem; }
.doc-clause h3 {
  font-size: .92rem; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; margin-bottom: .5rem;
}
.doc-clause p { margin-bottom: .7rem; }
.doc-clause p.doc-list { white-space: pre-line; text-align: left; padding-left: 1rem; }

.doc-fields { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
.doc-fields th, .doc-fields td {
  border: 1px solid #ddd6ca; padding: .55rem .8rem; vertical-align: top; font-size: .9rem;
}
.doc-fields th {
  width: 34%; text-align: left; background: #faf7f2; font-weight: 700;
  font-family: 'Nunito', system-ui, sans-serif; font-size: .82rem;
  letter-spacing: .03em; color: #4a4238;
}

/* --- Signature blocks ---------------------------------------------------- */
.doc-sign-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 1.25rem; }
.doc-sign-block {
  border: 1px solid #ddd6ca; border-radius: 4px; padding: 1.1rem 1.25rem; background: #fcfaf7;
}
.doc-sign-block .party {
  font-family: 'Nunito', system-ui, sans-serif; font-size: .72rem; font-weight: 800;
  letter-spacing: .14em; text-transform: uppercase; color: #6b6257;
}
.doc-sign-name {
  font-size: 1.05rem; font-weight: 700; border-bottom: 1px solid #1c1c1c;
  padding-bottom: .3rem; margin: 1.5rem 0 .45rem;
}
.doc-sign-meta {
  font-family: 'Nunito', system-ui, sans-serif; font-size: .82rem; color: #4a4a4a; line-height: 1.5;
}
.doc-sign-ref {
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: .7rem; color: #8a8175; margin-top: .4rem; word-break: break-all;
}
.doc-unsigned { color: #9a9186; font-style: italic; }

/* --- Approval / e-signature trail (Request Forms) ----------------------- */
.doc-trail { list-style: none; margin: 0; padding: 0; }
.doc-trail li {
  position: relative; padding: 0 0 1.35rem 1.9rem; border-left: 2px solid #e3ddd2;
}
.doc-trail li:last-child { border-left-color: transparent; padding-bottom: 0; }
.doc-trail li::before {
  content: ''; position: absolute; left: -7px; top: .35rem;
  width: 12px; height: 12px; border-radius: 50%;
  background: #fff; border: 2px solid #9a9186;
}
.doc-trail li.is-approved::before { border-color: #1f7a45; background: #1f7a45; }
.doc-trail li.is-rejected::before { border-color: #a32626; background: #a32626; }
.doc-trail li.is-pending::before { border-style: dashed; }
.doc-trail .step-head {
  font-family: 'Nunito', system-ui, sans-serif; font-size: .74rem; font-weight: 800;
  letter-spacing: .1em; text-transform: uppercase; color: #6b6257;
}
.doc-trail .step-actor { font-size: 1rem; font-weight: 700; }
.doc-trail .step-meta {
  font-family: 'Nunito', system-ui, sans-serif; font-size: .82rem; color: #4a4a4a;
}
.doc-trail .step-remarks {
  font-family: 'Nunito', system-ui, sans-serif; font-size: .85rem;
  background: #faf7f2; border-left: 3px solid #ddd6ca;
  padding: .45rem .7rem; margin-top: .45rem; border-radius: 0 3px 3px 0;
}
.doc-trail .step-signature {
  margin-top: .5rem; display: inline-block;
  border-bottom: 1px solid #c9c2b4; padding-bottom: 2px;
}
.doc-trail .step-signature svg { display: block; }
.doc-trail .step-ref {
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: .68rem; color: #8a8175; margin-top: .3rem;
}

/* --- Responsive and print ------------------------------------------------ */
@media (max-width: 720px) {
  .doc-sheet { padding: 1.75rem 1.25rem; }
  .doc-sign-grid { grid-template-columns: 1fr; }
  .doc-fields th { width: 40%; }
}
@media print {
  .app-navbar, .app-sidebar, footer, .doc-toolbar, .alert, .modal { display: none !important; }
  .app-content, .app-wrapper { margin: 0 !important; padding: 0 !important; display: block !important; }
  .doc-sheet { box-shadow: none; border: 0; max-width: none; padding: 0; }
  body { background: #fff; }
}
</style>
<?php
}
