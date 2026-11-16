## Summary

<!-- What does this change and why? Link related issues with "Closes #123". -->

## Type of change

- [ ] Bug fix
- [ ] New feature
- [ ] Refactor (no behaviour change)
- [ ] Documentation
- [ ] Dependencies or CI

## How was this tested?

<!-- Describe manual testing, and list new or changed tests. -->

- [ ] `php artisan test` passes
- [ ] `./vendor/bin/pint --test` passes for the files I changed
- [ ] Frontend changes: `npm run build` and `npm run test:js` pass
- [ ] New behaviour has tests, including the refused or unauthorised path where relevant

## Checklist

- [ ] Data stays scoped to the user's organization (and warehouse, where warehouse restrictions apply)
- [ ] New routes, API endpoints, GraphQL fields and MCP tools check permissions consistently across surfaces
- [ ] Database changes include a migration that works on MySQL, PostgreSQL and SQLite
- [ ] New user-facing text uses translation keys (added to `en.json`)
- [ ] UI changes work with a keyboard, at narrow widths, and in light and dark mode (see [ACCESSIBILITY.md](../ACCESSIBILITY.md))
- [ ] Documentation updated (`docs/site/`, `CHANGELOG.md`) where behaviour changes
- [ ] No secrets, credentials or real customer data in code, tests or screenshots

## Screenshots

<!-- For UI changes, before and after. Remove any personal data. -->

<!--
Security vulnerabilities: do not describe an unfixed vulnerability in a public pull request.
Report it privately first: https://github.com/Inventoros/Inventoros/security/advisories/new
-->
