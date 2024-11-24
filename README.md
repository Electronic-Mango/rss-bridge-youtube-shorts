# YouTube shorts for RSS-Bridge

Modified [RSS-Bridge](https://github.com/RSS-Bridge/rss-bridge/tree/master) [YouTube bridge](https://github.com/RSS-Bridge/rss-bridge/blob/master/bridges/YoutubeBridge.php) responding only with YouTube shorts.

Looking up shorts in a channel/user/custom name is supported.
Looking up by search or playlist is not.

This bridge can have a increased response time compared to regular YouTube bridge, as you need to load a page for each short to get their published date.
You can limit how many items are returned, which should improve the response time.
