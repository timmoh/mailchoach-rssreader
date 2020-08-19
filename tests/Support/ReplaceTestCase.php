<?php

namespace Timmoh\MailcoachRssReader\Tests\Support;

use DOMDocument;
use Exception;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Spatie\Mailcoach\Exceptions\CouldNotSendCampaign;
use Spatie\Mailcoach\Models\Campaign;
use Symfony\Component\Process\Process;
use Timmoh\MailcoachRssReader\Tests\TestCase;
use Spatie\Mailcoach\Support\Replacers\Replacer;

class ReplaceTestCase extends TestCase {

    protected $replacerClasses =[];


    public function execute(Campaign $campaign) {
        $this->ensureValidHtml($campaign);
        $this->ensureEmailHtmlHasSingleRootElement($campaign);
        $campaign->email_html = $campaign->htmlWithInlinedCss();
        $this->replacePlaceholders($campaign);
        $campaign->save();
    }

    protected function ensureValidHtml(Campaign $campaign)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        try {
            $html = preg_replace('/&(?!amp;)/', '&amp;', $campaign->html);

            $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING);

            return true;
        } catch (Exception $exception) {
            throw CouldNotSendCampaign::invalidContent($campaign, $exception);
        }
    }

    protected function ensureEmailHtmlHasSingleRootElement($campaign)
    {
        $campaign->html = trim(
            preg_replace('~<(?:!DOCTYPE|/?(?:html))[^>]*>\s*~i', '', $campaign->html)
        );

        if (! Str::startsWith(trim($campaign->html), '<html')) {
            $campaign->html = '<html>'.$campaign->html;
        }

        if (! Str::endsWith(trim($campaign->html), '</html>')) {
            $campaign->html = $campaign->html.'</html>';
        }
    }

    public function replacePlaceholders(Campaign $campaign): void {
        if(empty($this->replacerClasses)){
            $this->replacerClasses = $this->getReplacerClassesInFolder();
        }


        $newText = collect($this->replacerClasses)
            ->map(fn(string $className) => app($className))
            ->filter(fn(object $class) => $class instanceof Replacer)
            ->reduce(fn(string $html, Replacer $replacer) => $replacer->replace($html, $campaign), $campaign->email_html);
    }

    private function getReplacerClassesInFolder() {
        $path  = __DIR__ . '/../../src/Support/Replacers';
        $fqcns = [];

        $allFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        $phpFiles = new RegexIterator($allFiles, '/\.php$/');
        foreach ($phpFiles as $phpFile) {
            $content   = file_get_contents($phpFile->getRealPath());
            $tokens    = token_get_all($content);
            $namespace = '';
            for ($index = 0; isset($tokens[$index]); $index++) {
                if (!isset($tokens[$index][0])) {
                    continue;
                }
                if (T_NAMESPACE === $tokens[$index][0]) {
                    $index += 2; // Skip namespace keyword and whitespace
                    while (isset($tokens[$index]) && is_array($tokens[$index])) {
                        $namespace .= $tokens[$index++][1];
                    }
                }
                if (T_CLASS === $tokens[$index][0] && T_WHITESPACE === $tokens[$index + 1][0] && T_STRING === $tokens[$index + 2][0]) {
                    $index   += 2; // Skip class keyword and whitespace
                    $fqcns[] = $namespace . '\\' . $tokens[$index][1];

                    # break if you have one class per file (psr-4 compliant)
                    # otherwise you'll need to handle class constants (Foo::class)
                    break;
                }
            }
        }
        return $fqcns;
    }
}

