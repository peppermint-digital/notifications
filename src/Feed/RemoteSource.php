<?php

namespace Peppermint\Notifications\Feed;

/**
 * Woher die Meldungen der anderen Systeme kommen.
 *
 * ## Warum das eine Schnittstelle ist
 *
 * Die beiden Seiten des Verbunds fragen VERSCHIEDEN:
 *
 * - Ein **Produkt** hat die Bruecke und ruft `AiBrain::providers()` /
 *   `AiBrain::gateway()`.
 * - **AI Brain** hat die Bruecke nicht — es ist der Hub. Es fragt seinen
 *   eigenen `ProductGateway` direkt.
 *
 * Beim ersten Bau stand nur der Bruecken-Weg da. Im Brain fiel die Pruefung
 * `class_exists(AiBrain::class)` still auf `false`, die Liste blieb leer,
 * und es sah aus wie „die anderen haben nichts" statt wie „ich habe gar
 * nicht gefragt". Genau die Art Fehlschlag, die niemand meldet.
 */
interface RemoteSource
{
    /**
     * Welche Systeme bieten ihre Meldungen an?
     *
     * @return array<int, array{slug: string, key?: string, label?: string}>
     */
    public function anbieter(): array;

    /**
     * Die Meldungen EINES Systems fuer diese Person.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \Throwable wenn das System nicht antwortet — der Aufrufer
     *                    entscheidet, ob das die Seite kostet (es tut es nicht).
     */
    public function holen(string $slug, string $adresse, int $grenze): array;
}
