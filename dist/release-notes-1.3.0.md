# AI Shopping Assistant for Magento 2: Release Notes

Merchant-facing "What's new" copy for the Adobe Commerce Marketplace listing.
Paste the relevant version into the portal's release-notes field. No em-dashes
(merchant-facing copy convention). Marketplace assets only; not shipped to
merchants (`dist/` is export-ignored from the Composer package).

## Version 1.3.0

Shoppers can now go from asking a question to placing an order without leaving
the conversation, and you choose exactly how far that goes.

### Choose how far checkout happens in the chat
A new setting under Stores > Configuration > IDEA89 > Checkout Experience lets
you pick one of four levels, each a step up from the one before:

- **Off**: a shopper who says "checkout" is sent to your cart page, exactly as
  the assistant has always behaved.
- **Express handoff**: the assistant shows a basket summary in the chat with one
  button straight to your checkout. It never touches your checkout itself. This
  is the shipped default.
- **Checkout in chat**: your own Magento checkout opens inside a panel over the
  conversation, with your payment methods, your shipping rules and your
  extensions. IDEA89 renders nothing payment related and never receives card
  details.
- **Native checkout (beta)**: the assistant collects delivery details in the
  conversation and places the order itself, using only the payment methods you
  explicitly allow.

A built in Test Checkout Panel checks your store for anything likely to stop the
framed checkout rendering, so you can see before you switch it on.

### Full window or in the chat
Checkout can take the whole screen, hiding the conversation behind it, or stay
inside the assistant panel alongside the chat. Full window is the default.

### A checkout bar that is always in reach
When the shopper has something in their basket, a full width bar sits above the
message box showing the item count and total. Tapping it goes through whichever
checkout level you chose, so it is a second way to reach checkout rather than a
new path. It hides itself on an empty basket, and you can switch it off.

### Nothing is retyped if something goes wrong
If checkout cannot finish in the chat, the shopper is taken to your normal
checkout page with the delivery details they already entered, so they carry on
rather than starting again.

### Order confirmation in the conversation
A completed order shows a green tick and the order number, first at full size
and then as a record that stays in the chat, so the shopper can find the number
again later.

### Let AI agents find your products (optional, off by default)
A new Agentic Commerce area publishes your catalog in a format AI shopping
assistants such as ChatGPT can read, so they can find and recommend your
products. It is off until you turn it on, because switching it on publishes
catalog data to third parties. You can protect the feed with an access token,
and IDEA89 never places an order or touches payment on this path.

### Assistant Name now sets the name shoppers see
The Assistant Name field previously described the assistant to the AI but did
not change the name above the conversation. It now sets both, and is shared with
your IDEA89 dashboard so the two always match.

### Compatibility
- Fully backward compatible. Existing installations keep working with no
  changes: Express handoff is the default, so checkout behaves exactly as it did
  before you touch anything.
- Agentic Commerce is off by default.
- Native checkout ships with no payment methods selected, so it places no orders
  until you choose which methods the assistant may use.
- Checkout Display and Assistant Name are stored in your IDEA89 account rather
  than in Magento, so they stay the same in your dashboard and your admin. If
  IDEA89 cannot be reached when you save either of those two fields, the change
  is refused and Magento tells you, rather than saving a value your dashboard
  never received.

### Updating
Run `composer update idea89/magento2-assistant`, then `bin/magento setup:upgrade`
and `bin/magento cache:flush`.

---

### Short version (for a length-limited "What's new" field)

Version 1.3.0 lets shoppers complete an order without leaving the chat, and lets
you choose how far that goes: hand off to your checkout, open your own checkout
in a panel, or let the assistant take delivery details and place the order.
Checkout can fill the screen or stay in the chat panel, a basket bar keeps
checkout one tap away, and details already entered carry over to your normal
checkout if anything goes wrong. Also adds an optional feed so AI shopping
assistants can find your products, off by default. Fully backward compatible:
the default keeps checkout exactly as it was.
