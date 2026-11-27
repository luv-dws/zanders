<?php declare(strict_types=1);

namespace ZandersTheme\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class WarningMessageTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('zanders_format_warning_message', $this->formatWarningMessage(...), ['is_safe' => ['html']]),
            new TwigFilter('zanders_format_warning_url_label', $this->formatWarningUrlLabel(...)),
        ];
    }

    public function formatWarningMessage(?string $message): string
    {
        if (!$message) {
            return '';
        }

        // Standardize p65warnings links
        $aTagPattern = '~<a\s+[^>]*href=["\']?(?:https?://)?(?:www\.)?p65warnings\.ca\.gov(?:/[^"\'\s>]*)?["\']?[^>]*>.*?</a>~is';
        if (preg_match($aTagPattern, $message)) {
            $message = preg_replace(
                $aTagPattern,
                '<a href="https://www.p65warnings.ca.gov/" target="_blank" rel="noopener noreferrer" class="zanders-warning-link">www.P65Warnings.ca.gov</a>',
                $message
            );
        } else {
            $p65Pattern = '~(?xi)
                (?:https?://)?
                (?:www\.)?
                p65warnings\.ca\.gov
                (?:/[^\s<]*)?
            ~';

            if (preg_match($p65Pattern, $message)) {
                $message = preg_replace(
                    $p65Pattern,
                    '<a href="https://www.p65warnings.ca.gov/" target="_blank" rel="noopener noreferrer" class="zanders-warning-link">www.P65Warnings.ca.gov</a>',
                    $message
                );
            }
        }

        return nl2br($message);
    }

    public function formatWarningUrlLabel(?string $url): string
    {
        if (!$url) {
            return '';
        }

        if (stripos($url, 'p65warnings.ca.gov') !== false) {
            return 'www.P65Warnings.ca.gov';
        }

        $clean = preg_replace('~^https?://~i', '', $url);
        return rtrim($clean, '/');
    }
}
