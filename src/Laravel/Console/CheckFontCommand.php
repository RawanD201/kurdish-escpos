<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Laravel\Console;

use Illuminate\Console\Command;
use Rawand\KurdishEscpos\FontCheck;
use Rawand\KurdishEscpos\Renderer;

class CheckFontCommand extends Command
{
    protected $signature = 'kurdish-escpos:check-font
                            {font : Path to a .ttf font file}
                            {--preview= : Save a sample receipt rendered with the font to this PNG file}';

    protected $description = 'Check whether a font can print Kurdish receipts';

    public function handle(): int
    {
        $path = (string) $this->argument('font');
        $result = FontCheck::check($path);

        $this->components->info($result['name']);
        foreach ($result['problems'] as $problem) {
            $this->components->error($problem);
        }
        foreach ($result['notes'] as $note) {
            $this->components->warn($note);
        }
        if ($result['ok']) {
            $this->components->info('The font is suitable. Set KURDISH_ESCPOS_FONT='.$path.' to use it.');
        }

        if ($preview = $this->option('preview')) {
            if (! $result['ok'] && $result['problems'] !== [] && str_contains($result['problems'][0], 'outlines')) {
                return self::FAILURE;
            }
            file_put_contents($preview, (new Renderer($path))->render(FontCheck::sampleReceipt())->png);
            $this->components->info("Sample receipt saved to {$preview}");
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
