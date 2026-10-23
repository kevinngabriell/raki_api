# Movira SSO (this app)

This app accepts single sign-on from Movira Core in addition to its normal password login.

* New endpoint: `account/sso.php` — `POST {"assertion": "<from Movira Core /auth/sso/assertion>"}`; issues the same token as the password login.
* New library: `sso/MoviraSso.php` — a byte-identical copy of `movira_api/docs/sibling_sso/MoviraSso.php`. Do not edit it here; change the canonical copy and re-copy.
* New `.env` keys: `MOVIRA_SSO_PUBLIC_KEY_PATH`, `MOVIRA_SSO_ISSUER`, `MOVIRA_CORE_SCHEMA`
* The password login is **unchanged**. It is to be removed at cutover, once this app's frontend uses "Login with Movira".

Design, rollout order, test results and the list of every sibling change: `movira_api/docs/SSO_Implementation_Notes.md`.
