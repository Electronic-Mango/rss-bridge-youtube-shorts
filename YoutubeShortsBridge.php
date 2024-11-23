<?php

/**
* RssBridgeYoutubeShorts
* Returns the newest shorts
* WARNING: to parse big playlists (over ~90 videos), you need to edit simple_html_dom.php:
* change: define('MAX_FILE_SIZE', 600000);
* into:   define('MAX_FILE_SIZE', 900000);  (or more)
*/
class YoutubeShortsBridge extends BridgeAbstract
{
    const NAME = 'YouTube Shorts Bridge';
    const URI = 'https://www.youtube.com';
    const CACHE_TIMEOUT = 3600;
    const DESCRIPTION = 'Returns the 10 newest shorts by username/channel/playlist or search';
    const PARAMETERS = [
        'By username' => [
            'u' => [
                'name' => 'username',
                'exampleValue' => 'LinusTechTips',
                'required' => true
            ]
        ],
        'By channel id' => [
            'c' => [
                'name' => 'channel id',
                'exampleValue' => 'UCw38-8_Ibv_L6hlKChHO9dQ',
                'required' => true
            ]
        ],
        'By custom name' => [
            'custom' => [
                'name' => 'custom name',
                'exampleValue' => 'LinusTechTips',
                'required' => true
            ]
        ],
        'global' => [
            'item_limit' => [
                'name' => 'upper limit of items',
                'type' => 'number',
                'title' => 'Upper limit of how many items should be returned, 99 by default',
                'required' => false,
                'exampleValue' => 10
            ]
        ]
    ];

    private const DEFAULT_ITEM_LIMIT = 99;

    private $feedName = '';
    private $feedUri = '';
    private $feedIconUrl = '';

    public function collectData()
    {
        $cacheKey = 'youtube_rate_limit';
        if ($this->cache->get($cacheKey)) {
            throw new RateLimitException();
        }
        try {
            $this->collectDataInternal();
        } catch (HttpException $e) {
            if ($e->getCode() === 429) {
                $this->cache->set($cacheKey, true, 60 * 16);
                throw new RateLimitException();
            }
            throw $e;
        }
    }

    private function collectDataInternal()
    {
        $username = $this->getInput('u');
        $channel = $this->getInput('c');
        $custom = $this->getInput('custom');
        
        if (!$username && !$channel && !$custom) {
            returnClientError("You must either specify either:\n - YouTube username (?u=...)\n - Channel id (?c=...)\n - Playlist id (?p=...)\n - Search (?s=...)");
        }

        if ($username) {
            $sourcePath = '/user/' . urlencode($username);
        } elseif ($channel) {
            $sourcePath = '/channel/' . urlencode($channel);
        } else {
            $sourcePath = '/' . urlencode($custom);
        }
        $this->feedUri = self::URI . $sourcePath . '/shorts';
        $html = $this->fetch($this->feedUri);
        $jsonData = $this->extractJsonFromHtml($html);

        if (!$jsonData || !isset($jsonData->contents)) {
            returnServerError('Unable to get data from YouTube');
        }

        $this->feedIconUrl = $jsonData->metadata->channelMetadataRenderer->avatar->thumbnails[0]->url;
        $jsonData = $jsonData->contents->twoColumnBrowseResultsRenderer->tabs[2];
        $jsonData = $jsonData->tabRenderer->content->richGridRenderer->contents;
        $this->listingFetchItemsFromJsonData($jsonData);
        $this->feedName = str_replace(' - YouTube', '', $html->find('title', 0)->plaintext);
    }

    private function fetchVideoDetails($videoId, &$author, &$description, &$timestamp)
    {
        $url = self::URI . "/watch?v=$videoId";
        $html = $this->fetch($url, true);

        // Skip unavailable videos
        if (strpos($html->innertext, 'IS_UNAVAILABLE_PAGE') !== false) {
            return;
        }

        $elAuthor = $html->find('span[itemprop=author] > link[itemprop=name]', 0);
        if (!is_null($elAuthor)) {
            $author = $elAuthor->getAttribute('content');
        }

        $elDatePublished = $html->find('meta[itemprop=datePublished]', 0);
        if (!is_null($elDatePublished)) {
            $timestamp = strtotime($elDatePublished->getAttribute('content'));
        }

        $jsonData = $this->extractJsonFromHtml($html);
        if (!isset($jsonData->contents)) {
            return;
        }

        $jsonData = $jsonData->contents->twoColumnWatchNextResults->results->results->contents ?? null;
        if (!$jsonData) {
            throw new \Exception('Unable to find json data');
        }
        $videoSecondaryInfo = null;
        foreach ($jsonData as $item) {
            if (isset($item->videoSecondaryInfoRenderer)) {
                $videoSecondaryInfo = $item->videoSecondaryInfoRenderer;
                break;
            }
        }
        if (!$videoSecondaryInfo) {
            returnServerError('Could not find videoSecondaryInfoRenderer. Error at: ' . $videoId);
        }

        $description = $videoSecondaryInfo->attributedDescription->content ?? '';

        // Default whitespace chars used by trim + non-breaking spaces (https://en.wikipedia.org/wiki/Non-breaking_space)
        $whitespaceChars = " \t\n\r\0\x0B\u{A0}\u{2060}\u{202F}\u{2007}";
        $descEnhancements = $this->ytBridgeGetVideoDescriptionEnhancements($videoSecondaryInfo, $description, self::URI, $whitespaceChars);
        foreach ($descEnhancements as $descEnhancement) {
            if (isset($descEnhancement['url'])) {
                $descBefore = mb_substr($description, 0, $descEnhancement['pos']);
                $descValue = mb_substr($description, $descEnhancement['pos'], $descEnhancement['len']);
                $descAfter = mb_substr($description, $descEnhancement['pos'] + $descEnhancement['len'], null);

                // Extended trim for the display value of internal links, e.g.:
                // FAVICON • Video Name
                // FAVICON / @ChannelName
                $descValue = trim($descValue, $whitespaceChars . '•/');

                $description = sprintf('%s<a href="%s" target="_blank">%s</a>%s', $descBefore, $descEnhancement['url'], $descValue, $descAfter);
            }
        }
    }

    private function ytBridgeGetVideoDescriptionEnhancements(
        object $videoSecondaryInfo,
        string $descriptionContent,
        string $baseUrl,
        string $whitespaceChars
    ): array {
        $commandRuns = $videoSecondaryInfo->attributedDescription->commandRuns ?? [];
        if (count($commandRuns) <= 0) {
            return [];
        }

        $enhancements = [];

        $boundaryWhitespaceChars = mb_str_split($whitespaceChars);
        $boundaryStartChars = array_merge($boundaryWhitespaceChars, [':', '-', '(']);
        $boundaryEndChars = array_merge($boundaryWhitespaceChars, [',', '.', "'", ')']);
        $hashtagBoundaryEndChars = array_merge($boundaryEndChars, ['#', '-']);

        $descriptionContentLength = mb_strlen($descriptionContent);

        $minPositionOffset = 0;

        $prevStartPosition = 0;
        $totalLength = 0;
        $maxPositionByStartIndex = [];
        foreach (array_reverse($commandRuns) as $commandRun) {
            $endPosition = $commandRun->startIndex + $commandRun->length;
            if ($endPosition < $prevStartPosition) {
                $totalLength += 1;
            }
            $totalLength += $commandRun->length;
            $maxPositionByStartIndex[$commandRun->startIndex] = $totalLength;
            $prevStartPosition = $commandRun->startIndex;
        }

        foreach ($commandRuns as $commandRun) {
            $commandMetadata = $commandRun->onTap->innertubeCommand->commandMetadata->webCommandMetadata ?? null;
            if (!isset($commandMetadata)) {
                continue;
            }

            $enhancement = null;

            /*
            $commandRun->startIndex can be offset by few positions in the positive direction
            when some multibyte characters (e.g. emojis, but maybe also others) are used in the plain text video description.
            (probably some difference between php and javascript in handling multibyte characters)
            This loop should correct the position in most cases. It searches for the next word (determined by a set of boundary chars) with the expected length.
            Several safeguards ensure that the correct word is chosen. When a link can not be matched,
            everything will be discarded to prevent corrupting the description.
            Hashtags require a different set of boundary chars.
            */
            $isHashtag = $commandMetadata->webPageType === 'WEB_PAGE_TYPE_BROWSE';
            $prevEnhancement = end($enhancements);
            $minPosition = $prevEnhancement === false ? 0 : $prevEnhancement['pos'] + $prevEnhancement['len'];
            $maxPosition = $descriptionContentLength - $maxPositionByStartIndex[$commandRun->startIndex];
            $position = min($commandRun->startIndex - $minPositionOffset, $maxPosition);
            while ($position >= $minPosition) {
                // The link display value can only ever include a new line at the end (which will be removed further below), never in between.
                $newLinePosition = mb_strpos($descriptionContent, "\n", $position);
                if ($newLinePosition !== false && $newLinePosition < $position + ($commandRun->length - 1)) {
                    $position = $newLinePosition - ($commandRun->length - 1);
                    continue;
                }

                $firstChar = mb_substr($descriptionContent, $position, 1);
                $boundaryStart = mb_substr($descriptionContent, $position - 1, 1);
                $boundaryEndIndex = $position + $commandRun->length;
                $boundaryEnd = mb_substr($descriptionContent, $boundaryEndIndex, 1);

                $boundaryStartIsValid = $position === 0 ||
                    in_array($boundaryStart, $boundaryStartChars) ||
                    ($isHashtag && $firstChar === '#');
                $boundaryEndIsValid = $boundaryEndIndex === $descriptionContentLength ||
                    in_array($boundaryEnd, $isHashtag ? $hashtagBoundaryEndChars : $boundaryEndChars);

                if ($boundaryStartIsValid && $boundaryEndIsValid) {
                    $minPositionOffset = $commandRun->startIndex - $position;
                    $enhancement = [
                        'pos' => $position,
                        'len' => $commandRun->length,
                    ];
                    break;
                }

                $position--;
            }

            if (!isset($enhancement)) {
                $this->logger->debug(sprintf('Position %d cannot be corrected in "%s"', $commandRun->startIndex, substr($descriptionContent, 0, 50) . '...'));
                // Skip to prevent the description from becoming corrupted
                continue;
            }

            // $commandRun->length sometimes incorrectly includes the newline as last char
            $lastChar = mb_substr($descriptionContent, $enhancement['pos'] + $enhancement['len'] - 1, 1);
            if ($lastChar === "\n") {
                $enhancement['len'] -= 1;
            }

            $commandUrl = parse_url($commandMetadata->url);
            if ($commandUrl['path'] === '/redirect') {
                parse_str($commandUrl['query'], $commandUrlQuery);
                $enhancement['url'] = urldecode($commandUrlQuery['q']);
            } elseif (isset($commandUrl['host'])) {
                $enhancement['url'] = $commandMetadata->url;
            } else {
                $enhancement['url'] = $baseUrl . $commandMetadata->url;
            }

            $enhancements[] = $enhancement;
        }

        if (count($enhancements) !== count($commandRuns)) {
            // At least one link can not be matched. Discard everything to prevent corrupting the description.
            return [];
        }

        // Sort by position in descending order to be able to safely replace values
        return array_reverse($enhancements);
    }

    private function fetch($url, bool $cache = false)
    {
        $header = ['Accept-Language: en-US'];
        $ttl = 86400 * 3; // 3d
        $stripNewlines = false;
        if ($cache) {
            return getSimpleHTMLDOMCached($url, $ttl, $header, [], true, true, DEFAULT_TARGET_CHARSET, $stripNewlines);
        }
        return getSimpleHTMLDOM($url, $header, [], true, true, DEFAULT_TARGET_CHARSET, $stripNewlines);
    }

    private function extractJsonFromHtml($html)
    {
        $scriptRegex = '/var ytInitialData = (.*?);<\/script>/';
        $result = preg_match($scriptRegex, $html, $matches);
        if (! $result) {
            $this->logger->debug('Could not find ytInitialData');
            return null;
        }
        $data = json_decode($matches[1]);
        return $data;
    }

    private function listingFetchItemsFromJsonData($jsonData)
    {
        $maxItemCount = $this->getInput('item_limit') ?: self::DEFAULT_ITEM_LIMIT;
        foreach ($jsonData as $item) {
            if (!isset($item->richItemRenderer)) {
                continue;
            }
            $wrapper = $item->richItemRenderer->content->shortsLockupViewModel;
            $videoId = $wrapper->onTap->innertubeCommand->reelWatchEndpoint->videoId;
            $title = $wrapper->overlayMetadata->primaryText->content ?? null;
            $author = null;
            $description = null;
            $timestamp = null;
            $this->fetchVideoDetails($videoId, $author, $description, $timestamp);
            $this->addItem($videoId, $title, $author, $description, $timestamp);
            if (count($this->items) >= $maxItemCount) {
                break;
            }
        }
    }

    private function addItem($videoId, $title, $author, $description, $timestamp, $thumbnail = '')
    {
        $description = nl2br($description);

        $item = [];
        // This should probably be uid?
        $item['id'] = $videoId;
        $item['title'] = $title;
        $item['author'] = $author ?? '';
        $item['timestamp'] = $timestamp;
        $item['uri'] = self::URI . '/watch?v=' . $videoId;
        if (!$thumbnail) {
            // Fallback to default thumbnail if there aren't any provided.
            $thumbnail = '0';
        }
        $thumbnailUri = str_replace('/www.', '/img.', self::URI) . '/vi/' . $videoId . '/' . $thumbnail . '.jpg';
        $item['content'] = sprintf('<a href="%s"><img src="%s" /></a><br />%s', $item['uri'], $thumbnailUri, $description);
        $this->items[] = $item;
    }

    private function decodeTitle($title)
    {
        // convert both &#1234; and &quot; to UTF-8
        return html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    }

    public function getURI()
    {
        if (!is_null($this->getInput('p'))) {
            return static::URI . '/playlist?list=' . $this->getInput('p');
        } elseif ($this->feedUri) {
            return $this->feedUri;
        }

        return parent::getURI();
    }

    public function getName()
    {
        switch ($this->queriedContext) {
            case 'By username':
            case 'By channel id':
            case 'By custom name':
            case 'By playlist Id':
            case 'Search result':
                return htmlspecialchars_decode($this->feedName) . ' - YouTube';
            default:
                return parent::getName();
        }
    }

    public function getIcon()
    {
        if (empty($this->feedIconUrl)) {
            return parent::getIcon();
        } else {
            return $this->feedIconUrl;
        }
    }
}
