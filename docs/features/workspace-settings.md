# Workspace settings

## Sign out and check permissions

Choose **Log Out** below your account in the sidebar. On mobile, open the navigation menu first. Every signed-in user can sign out, including users without access to organization settings.

Under **Users**, open a user and search **Effective permissions** to see which permissions their base and custom roles grant. This requires **View Users**. Warehouse assignments and restrictions on individual records still apply even when a role grants an action.

To test the complete experience, create a separate member account with the desired roles, sign out, and sign in as that account. Administrator accounts always have all permissions. The permission list does not impersonate the user, and Log Out ends your own session.

## Organization currency

Set the currency in **Settings → Organization → Regional Settings**. New customer, supplier and purchase order forms use that currency, and their selectors include the supported currencies. New customers and suppliers created through the API or imports also use this default when currency is omitted or blank.

Existing records retain their saved currency. Selecting a supplier on a new purchase order uses that supplier's currency when it has one. Changing the organization default does not convert prices or rewrite historical transactions.

## Company logo

In **Settings → Organization → General Information**, choose a **Company logo** and save it. Upload a PNG, JPEG or WebP image up to 2 MB and 2048 × 2048 pixels. **Remove logo** restores the organization's initial in the workspace header. Updating the logo requires **View Settings** and **Manage Organization**.

The logo appears beside the organization name on desktop and mobile. Inventoros branding and license information appear in the footer. Logos are stored in the application's private storage and served only to users in that organization; no public storage symlink is needed.

When deploying this change from source, run the database migrations and rebuild frontend assets as part of the normal update process. Include `storage/app/private` in backups to preserve uploaded logos.
