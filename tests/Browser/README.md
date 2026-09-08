# Browser suite

End-to-end journeys through a real browser: Pest's browser plugin, Playwright
underneath, Chromium.

## Running it

```bash
docker compose exec app composer test:browser
```

No separate server needed. The plugin boots Laravel's HTTP kernel inside an
in-process Amp server, so `RefreshDatabase` works exactly as it does in the
Feature suite — the browser and the test share one connection and one
transaction, and fixtures built in `beforeEach` are visible to the page.

Assets come from `public/build`, so a stale bundle produces a stale page. Run
`npm run build` after changing anything under `resources/js`, or the suite is
testing the previous version of the interface.

## Why the dev image carries a browser

Playwright pins a Chromium build per release and refuses to drive anything
else — Debian's `chromium` package is not a substitute, and pointing at it
fails with "Playwright was just installed or updated" at the first navigation.
So the dev stage of `docker/app/Dockerfile` installs Playwright's own browser
into `/ms-playwright`, which is outside `/app/node_modules` and therefore
survives the named volume that masks it at run time.

The production image has none of this. A browser in a production image is
several hundred megabytes of attack surface that never serves a request.

## What belongs here, and what does not

These tests are slow — seconds each, against milliseconds for a Feature test —
so they earn their place only by catching what cheaper tests cannot:

- **a page that does not render.** A component that throws on mount still
  returns 200, and every HTTP assertion passes while the user sees nothing.
  `assertNoJavaScriptErrors()` is the point of most of this suite.
- **client-side behaviour the server never sees.** The running totals on a
  journal form, a confirmation step, a control hidden by permission.
- **a journey across screens.** Post an entry, then find it on the chart, the
  trial balance and the general ledger — the assertion is that the screens
  agree with each other.

What does *not* belong here: anything a Feature test can assert. Authorisation
matrices, validation rules, posting rules and cross-tenant isolation are all
faster, clearer and more thorough against the HTTP layer or the domain
directly.

Selectors are CSS or field names, never visible copy for controls. A test that
breaks when a label is reworded is a test people learn to ignore.
