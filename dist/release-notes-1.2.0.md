# AI Shopping Assistant for Magento 2: Release Notes

Merchant-facing "What's new" copy for the Adobe Commerce Marketplace listing.
Paste the relevant version into the portal's release-notes field. No em-dashes
(merchant-facing copy convention). Marketplace assets only; not shipped to
merchants (`dist/` is export-ignored from the Composer package).

## Version 1.2.0

Two shopper-facing upgrades that help your customers find and buy with more
confidence.

### Full product view inside the chat
Shoppers can now open a complete product view without leaving the conversation.
Product images, pricing, options such as size and color swatches, and the Add to
Cart button all appear in a clean popup built from your own store's product page.
Customers get everything they need to decide in one place, and adding to the cart
behaves exactly the way it does on your storefront.

### Personalized, real-time help (optional)
Turn on Personalization in the extension settings to let the assistant recognize
signed-in customers and confirm current prices and stock while they shop, so
answers stay accurate and up to date. This runs securely on your own store, so
customer information never leaves your server.

### Compatibility
- Fully backward compatible. Existing installations keep working with no changes.
- Personalization is off by default and activates only after you enable it and
  add your signing secret.

### Updating
Run `composer update idea89/magento2-assistant`, then `bin/magento setup:upgrade`
and `bin/magento cache:flush`.

---

### Short version (for a length-limited "What's new" field)

Version 1.2.0 adds a full product view inside the chat, so shoppers can see
images, pricing, options and swatches and add to cart without leaving the
conversation. It also introduces optional Personalization: when enabled, the
assistant recognizes signed-in customers and confirms live prices and stock,
all handled securely on your own store. Fully backward compatible, and
Personalization is off by default.
