<?php

/**
 * Telegram API scraper — fetches core.telegram.org and saves telegram-api.json
 * Used by GitHub Actions in laraxgram/telegram-api-data
 */
class TelegramApiScraper
{
    private string $apiUrl  = 'https://core.telegram.org/bots/api';
    private string $outFile;
    private array  $methods = [];
    private array  $types   = [];

    public function __construct(string $outFile)
    {
        $this->outFile = $outFile;
    }

    private function fetchPage(): string
    {
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $html     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || !$html) {
            throw new Exception("Fetch failed. HTTP $httpCode");
        }

        return $html;
    }

    private function getDescription(DOMElement $heading): string
    {
        $text = '';
        $node = $heading->nextSibling;

        while ($node) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                if ($node->nodeName === 'p') {
                    $t = trim($node->textContent);
                    if ($t) $text .= $t . ' ';
                } elseif (in_array($node->nodeName, ['h3', 'h4', 'table'])) {
                    break;
                }
            }
            $node = $node->nextSibling;
        }

        return trim($text);
    }

    private function parseMethods(string $html): void
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath    = new DOMXPath($dom);
        $headings = $xpath->query("//h4[not(contains(@class,'no-link'))]");

        foreach ($headings as $heading) {
            $name = trim($heading->textContent);
            if (!preg_match('/^[a-z]/', $name) || strlen($name) > 50) continue;

            $parameters = [];
            $node       = $heading->nextSibling;

            while ($node) {
                if ($node->nodeType === XML_ELEMENT_NODE) {
                    if ($node->nodeName === 'table') {
                        $rows = $xpath->query('.//tr', $node);
                        foreach ($rows as $i => $row) {
                            if ($i === 0) continue;
                            $cells = $xpath->query('.//td', $row);
                            if ($cells->length >= 3) {
                                $pName     = trim($cells->item(0)->textContent);
                                $pType     = trim($cells->item(1)->textContent);
                                $pRequired = trim($cells->item(2)->textContent);
                                $pDesc     = $cells->length > 3 ? trim($cells->item(3)->textContent) : '';

                                if ($pName && !in_array($pName, ['Field', 'Parameter'])) {
                                    $parameters[] = [
                                        'name'        => $pName,
                                        'type'        => $pType,
                                        'required'    => strtolower($pRequired) === 'yes',
                                        'description' => $pDesc,
                                    ];
                                }
                            }
                        }
                        break;
                    } elseif (in_array($node->nodeName, ['h3', 'h4'])) {
                        break;
                    }
                }
                $node = $node->nextSibling;
            }

            usort($parameters, fn($a, $b) => $b['required'] <=> $a['required']);

            $this->methods[$name] = [
                'name'        => $name,
                'description' => $this->getDescription($heading),
                'parameters'  => $parameters,
            ];
        }
    }

    private function parseTypes(string $html): void
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath    = new DOMXPath($dom);
        $headings = $xpath->query("//h4[not(contains(@class,'no-link'))]");

        foreach ($headings as $heading) {
            $name = trim($heading->textContent);
            if (!preg_match('/^[A-Z]/', $name) || strlen($name) > 50) continue;
            if ($name === 'Determining list of commands') continue;

            $fields        = [];
            $isUnion       = false;
            $unionSubtypes = [];
            $tempNode      = $heading->nextSibling;

            while ($tempNode && !$isUnion) {
                if ($tempNode->nodeType === XML_ELEMENT_NODE) {
                    if ($tempNode->nodeName === 'p') {
                        $text = trim($tempNode->textContent);
                        if (preg_match('/(?:can be one of|currently,?\s+it can be one of|should be one of|following.*(?:types|scopes) (?:are|is) supported|the following.*types)/i', $text)) {
                            $isUnion  = true;
                            $listNode = $tempNode->nextSibling;
                            while ($listNode) {
                                if ($listNode->nodeType === XML_ELEMENT_NODE && $listNode->nodeName === 'ul') {
                                    foreach ($xpath->query('.//li', $listNode) as $item) {
                                        $sub = trim($item->textContent);
                                        if (preg_match('/^[A-Z][a-zA-Z0-9]+/', $sub, $m)) {
                                            $unionSubtypes[] = $m[0];
                                        }
                                    }
                                    break;
                                }
                                $listNode = $listNode->nextSibling;
                            }
                            break;
                        }
                    } elseif (in_array($tempNode->nodeName, ['h3', 'h4', 'table'])) {
                        break;
                    }
                }
                $tempNode = $tempNode->nextSibling;
            }

            if ($isUnion && !empty($unionSubtypes)) {
                $fields[] = ['name' => '__union__', 'type' => implode('|', $unionSubtypes), 'description' => ''];
            } else {
                $node = $heading->nextSibling;
                while ($node) {
                    if ($node->nodeType === XML_ELEMENT_NODE) {
                        if ($node->nodeName === 'table') {
                            $rows = $xpath->query('.//tr', $node);
                            foreach ($rows as $i => $row) {
                                if ($i === 0) continue;
                                $cells = $xpath->query('.//td', $row);
                                if ($cells->length >= 2) {
                                    $fName = trim($cells->item(0)->textContent);
                                    $fType = trim($cells->item(1)->textContent);
                                    $fDesc = $cells->length > 2 ? trim($cells->item(2)->textContent) : '';
                                    if ($fName && !in_array($fName, ['Field', 'Parameter'])) {
                                        $fields[] = ['name' => $fName, 'type' => $fType, 'description' => $fDesc];
                                    }
                                }
                            }
                            break;
                        } elseif (in_array($node->nodeName, ['h3', 'h4'])) {
                            break;
                        }
                    }
                    $node = $node->nextSibling;
                }
            }

            $this->types[$name] = [
                'name'        => $name,
                'description' => $this->getDescription($heading),
                'fields'      => $fields,
            ];
        }
    }

    public function run(): void
    {
        echo "Fetching {$this->apiUrl} ..." . PHP_EOL;
        $html = $this->fetchPage();

        echo "Parsing methods..." . PHP_EOL;
        $this->parseMethods($html);
        echo "  Methods: " . count($this->methods) . PHP_EOL;

        echo "Parsing types..." . PHP_EOL;
        $this->parseTypes($html);
        echo "  Types: " . count($this->types) . PHP_EOL;

        $data = [
            'scraped_at' => date('c'),
            'source'     => $this->apiUrl,
            'methods'    => $this->methods,
            'types'      => $this->types,
        ];

        $dir = dirname($this->outFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!file_put_contents($this->outFile, $json)) {
            throw new Exception("Write failed: {$this->outFile}");
        }

        echo "Saved: {$this->outFile}" . PHP_EOL;
    }
}

if (php_sapi_name() !== 'cli') exit(1);

try {
    $outFile = __DIR__ . '/../telegram-api.json';
    (new TelegramApiScraper($outFile))->run();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
