<?php

declare(strict_types=1);

namespace Fium\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class CrashOnceHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $baseDir = $request->attribute('base_dir') ?? dirname(__DIR__, 2);
        $flagDirectory = $baseDir . '/storage/runtime';
        $flagPath = $flagDirectory . '/crash-once.flag';

        if (!is_dir($flagDirectory) && !mkdir($flagDirectory, 0777, true) && !is_dir($flagDirectory)) {
            throw new \RuntimeException('Unable to prepare runtime crash probe storage.');
        }

        if (!is_file($flagPath)) {
            if (file_put_contents($flagPath, "armed\n") === false) {
                throw new \RuntimeException('Unable to arm runtime crash probe.');
            }

            exit(75);
        }

        if (!unlink($flagPath)) {
            throw new \RuntimeException('Unable to clear runtime crash probe flag.');
        }

        return Response::json([
            'ok' => true,
            'recovered' => true,
            'route' => (string) $request->matchedRoute(),
        ]);
    }
}