# 👋 Hello World Plugin

This plugin literally does nothing useful. Like, seriously nothing. It just says "hello" in the logs and takes up disk space. But that's okay! It's here to demonstrate how plugins work in InventorOS.

## What Does It Do?

Nothing. Well, almost nothing:
- ✅ Says hello in the logs when loaded
- ✅ Shows you how plugin files are structured
- ✅ Gives you something to safely delete when you want to feel productive
- ❌ Doesn't break anything
- ❌ Doesn't send your data anywhere
- ❌ Doesn't judge your life choices

## Can I Delete It?

**YES! PLEASE!** We actually encourage it. This plugin is like training wheels - great for learning, but you'll want to remove it eventually. Go ahead, we won't be offended. Promise. 🤗

## Installation

Wait, you want to *install* this? It's already here! But if you really must:

1. Upload this plugin folder to `/plugins/hello-world`
2. Activate it from the Plugins page
3. Check your logs to see it say hello
4. Feel accomplished
5. Delete it

## For Developers

If you're building your own plugin, check out `Plugin.php` to see:
- How to use `add_action()` and `add_filter()`
- How to structure plugin code
- How to make your plugin way more useful than this one

There are commented examples you can uncomment to see actions and filters in action (pun intended).

## Structure

```
hello-world/
├── plugin.json           # Plugin metadata (with a quirky description)
├── Plugin.php            # Main file that does nothing productively
└── README.md             # This file you're reading right now
```

That's it! Just two required files. Everything is handled through function-based hooks in `Plugin.php`.

## License

Do whatever you want with this. Seriously. We don't care. It's a Hello World plugin. 🎉

---

*P.S. - If you're reading this in the GitHub repo, you're awesome. If you found a bug in a plugin that does nothing, that's actually impressive.*
