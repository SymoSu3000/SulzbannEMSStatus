<?php

declare(strict_types=1);

class EMSStatus extends IPSModule
{
    /*
     * ============================================================
     * BESTEHENDE EMS-STATUSQUELLE
     * ============================================================
     *
     * Diese Variable enthält bereits die aufbereitete
     * EMS-Statusübersicht.
     *
     * Dadurch bauen wir die bestehende EMS-Logik NICHT nochmals
     * nach und müssen keine neuen Datenpunkt-IDs erraten.
     */

    private const EMS_STATUS_ID = 19206;


    public function Create(): void
    {
        parent::Create();

        /*
         * Native SDK-Visualisierung.
         *
         * module.html wird einmal geladen.
         * Danach werden nur noch Werte übertragen.
         */

        $this->SetVisualizationType(1);
    }


    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        /*
         * Bestehende EMS-Statusvariable beobachten.
         */

        if (IPS_VariableExists(self::EMS_STATUS_ID)) {

            $this->RegisterMessage(
                self::EMS_STATUS_ID,
                VM_UPDATE
            );
        }
    }


    /* ============================================================
       VISUALISIERUNG
    ============================================================ */

    public function GetVisualizationTile(): string
    {
        $file =
            __DIR__ .
            DIRECTORY_SEPARATOR .
            'module.html';


        if (!file_exists($file)) {

            return
                '<div style="padding:20px;">' .
                'module.html wurde nicht gefunden.' .
                '</div>';
        }


        $html =
            file_get_contents($file);


        if ($html === false) {

            return
                '<div style="padding:20px;">' .
                'module.html konnte nicht gelesen werden.' .
                '</div>';
        }


        return $html;
    }


    /* ============================================================
       MESSAGE SINK
    ============================================================ */

    public function MessageSink(
        $TimeStamp,
        $SenderID,
        $Message,
        $Data
    ): void {

        parent::MessageSink(
            $TimeStamp,
            $SenderID,
            $Message,
            $Data
        );


        if (
            $Message === VM_UPDATE &&
            $SenderID === self::EMS_STATUS_ID
        ) {

            $this->SendLiveValues();
        }
    }


    /* ============================================================
       REQUEST ACTION
    ============================================================ */

    public function RequestAction(
        $Ident,
        $Value
    ): void {

        if ($Ident === 'Refresh') {

            $this->SendLiveValues();

            return;
        }


        throw new Exception(
            'Invalid Ident: ' .
            $Ident
        );
    }


    /* ============================================================
       STATUSQUELLE LESEN
    ============================================================ */

    private function ReadEMSStatus(): string
    {
        if (!IPS_VariableExists(self::EMS_STATUS_ID)) {

            return '';
        }


        $value =
            GetValue(
                self::EMS_STATUS_ID
            );


        if (!is_string($value)) {

            $value =
                (string) $value;
        }


        /*
         * Falls die alte Statusvariable HTML enthält:
         *
         * Zeilenumbrüche vor dem Entfernen der Tags erhalten.
         */

        $value =
            preg_replace(
                '/<br\s*\/?>/i',
                "\n",
                $value
            );


        $value =
            preg_replace(
                '/<\/(div|p|li|tr|h1|h2|h3|strong)>/i',
                "$0\n",
                $value
            );


        $value =
            strip_tags(
                $value
            );


        $value =
            html_entity_decode(
                $value,
                ENT_QUOTES |
                ENT_HTML5,
                'UTF-8'
            );


        /*
         * Geschützte Leerzeichen normalisieren.
         */

        $value =
            str_replace(
                "\xC2\xA0",
                ' ',
                $value
            );


        /*
         * Mehrfache Leerzeichen reduzieren.
         */

        $value =
            preg_replace(
                '/[ \t]+/',
                ' ',
                $value
            );


        /*
         * Mehrere Leerzeilen reduzieren.
         */

        $value =
            preg_replace(
                "/\n[ \t]*\n+/",
                "\n",
                $value
            );


        return trim(
            $value
        );
    }


    /* ============================================================
       REGEX HELPERS
    ============================================================ */

    private function ExtractFloat(
        string $text,
        string $pattern
    ): ?float {

        if (
            preg_match(
                $pattern,
                $text,
                $match
            ) !== 1
        ) {

            return null;
        }


        $value =
            str_replace(
                ',',
                '.',
                $match[1]
            );


        if (!is_numeric($value)) {

            return null;
        }


        return (float) $value;
    }


    private function ExtractInteger(
        string $text,
        string $pattern
    ): ?int {

        if (
            preg_match(
                $pattern,
                $text,
                $match
            ) !== 1
        ) {

            return null;
        }


        if (!is_numeric($match[1])) {

            return null;
        }


        return (int) $match[1];
    }


    private function ExtractString(
        string $text,
        string $pattern
    ): ?string {

        if (
            preg_match(
                $pattern,
                $text,
                $match
            ) !== 1
        ) {

            return null;
        }


        return trim(
            $match[1]
        );
    }


    /* ============================================================
       EMS STATUS ZERLEGEN
    ============================================================ */

    private function ParseEMSStatus(
        string $text
    ): array {

        /*
         * --------------------------------------------------------
         * PV aktuell
         *
         * Beispiel:
         * PV 1.7 kW
         * --------------------------------------------------------
         */

        $pv =
            $this->ExtractFloat(
                $text,
                '/\bPV\s+(-?\d+(?:[.,]\d+)?)\s*kW\b/i'
            );


        /*
         * --------------------------------------------------------
         * Netz
         *
         * Alte Statusanzeige unterscheidet:
         *
         * Einspeisung 0.1 kW
         * Netzbezug 0.1 kW
         *
         * Unsere neue Darstellung:
         *
         * Einspeisung = negativ
         * Bezug        = positiv
         * --------------------------------------------------------
         */

        $grid = null;


        $export =
            $this->ExtractFloat(
                $text,
                '/Einspeisung\s+(-?\d+(?:[.,]\d+)?)\s*kW/i'
            );


        if ($export !== null) {

            $grid =
                -abs($export);
        }


        if ($grid === null) {

            $import =
                $this->ExtractFloat(
                    $text,
                    '/Netzbezug\s+(-?\d+(?:[.,]\d+)?)\s*kW/i'
                );


            if ($import !== null) {

                $grid =
                    abs($import);
            }
        }


        /*
         * --------------------------------------------------------
         * SOC
         * --------------------------------------------------------
         */

        $soc =
            $this->ExtractFloat(
                $text,
                '/\bSOC\s+(-?\d+(?:[.,]\d+)?)\s*%/i'
            );


        /*
         * --------------------------------------------------------
         * Gesamtlimit
         * --------------------------------------------------------
         */

        $limit =
            $this->ExtractFloat(
                $text,
                '/Gesamtlimit\s+(-?\d+(?:[.,]\d+)?)\s*kW/i'
            );


        /*
         * --------------------------------------------------------
         * Nächstes Ziel
         *
         * Beispiel:
         *
         * Nächstes Ziel 17:00 / 100%
         * --------------------------------------------------------
         */

        $targetTime =
            $this->ExtractString(
                $text,
                '/N[aä]chstes\s+Ziel\s+([0-2]?\d:[0-5]\d)/iu'
            );


        $targetSOC =
            $this->ExtractFloat(
                $text,
                '/N[aä]chstes\s+Ziel\s+[0-2]?\d:[0-5]\d\s*\/\s*(-?\d+(?:[.,]\d+)?)\s*%/iu'
            );


        /*
         * --------------------------------------------------------
         * SOC-Ladebedarf
         * --------------------------------------------------------
         */

        $socNeed =
            $this->ExtractFloat(
                $text,
                '/SOC-Ladebedarf\s+(-?\d+(?:[.,]\d+)?)\s*kW/i'
            );


        /*
         * --------------------------------------------------------
         * Verbraucher
         * --------------------------------------------------------
         */

        $consumers =
            $this->ExtractInteger(
                $text,
                '/Verbraucher\s+(\d+)/i'
            );


        /*
         * --------------------------------------------------------
         * PV-Prognose
         * --------------------------------------------------------
         */

        $forecastEnergy =
            $this->ExtractFloat(
                $text,
                '/PV-Prognose\s+(-?\d+(?:[.,]\d+)?)\s*kWh/i'
            );


        /*
         * --------------------------------------------------------
         * Peak
         * --------------------------------------------------------
         */

        $forecastPeak =
            $this->ExtractFloat(
                $text,
                '/Peak\s+(-?\d+(?:[.,]\d+)?)\s*kW/i'
            );


        /*
         * --------------------------------------------------------
         * Dauer
         * --------------------------------------------------------
         */

        $forecastDuration =
            $this->ExtractFloat(
                $text,
                '/Dauer\s+(-?\d+(?:[.,]\d+)?)\s*h/i'
            );


        return [

            'pv' =>
                $pv,

            'grid' =>
                $grid,

            'soc' =>
                $soc,

            'limit' =>
                $limit,

            'targetTime' =>
                $targetTime,

            'targetSOC' =>
                $targetSOC,

            'socNeed' =>
                $socNeed,

            'consumers' =>
                $consumers,

            'forecastEnergy' =>
                $forecastEnergy,

            'forecastPeak' =>
                $forecastPeak,

            'forecastDuration' =>
                $forecastDuration

        ];
    }


    /* ============================================================
       DATEN SENDEN
    ============================================================ */

    private function SendLiveValues(): void
    {
        $text =
            $this->ReadEMSStatus();


        if ($text === '') {

            $payload = [

                'pv' => null,
                'grid' => null,
                'soc' => null,
                'limit' => null,

                'targetTime' => null,
                'targetSOC' => null,

                'socNeed' => null,
                'consumers' => null,

                'forecastEnergy' => null,
                'forecastPeak' => null,
                'forecastDuration' => null

            ];

        } else {

            $payload =
                $this->ParseEMSStatus(
                    $text
                );
        }


        $json =
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );


        if ($json === false) {
            return;
        }


        /*
         * Nur Werte aktualisieren.
         *
         * Die HTML-Kachel wird NICHT neu aufgebaut.
         */

        $this->UpdateVisualizationValue(
            $json
        );
    }
}
