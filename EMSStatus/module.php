<?php

declare(strict_types=1);

class EMSStatus extends IPSModule
{
    /*
     * Bestehende EMS-Statusübersicht.
     * Daraus übernehmen wir weiterhin die bereits berechneten Werte.
     */
    private const EMS_STATUS_ID = 19206;


    public function Create(): void
    {
        parent::Create();

        $this->SetVisualizationType(1);
    }


    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_VariableExists(self::EMS_STATUS_ID)) {
            $this->RegisterMessage(
                self::EMS_STATUS_ID,
                VM_UPDATE
            );
        }
    }


    public function GetVisualizationTile(): string
    {
        $file =
            __DIR__ .
            DIRECTORY_SEPARATOR .
            'module.html';

        if (!file_exists($file)) {
            return '<div style="padding:20px;">module.html wurde nicht gefunden.</div>';
        }

        $html =
            file_get_contents($file);

        if ($html === false) {
            return '<div style="padding:20px;">module.html konnte nicht gelesen werden.</div>';
        }

        return $html;
    }


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
         * HTML-Zeilenumbrüche erhalten.
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

        $value =
            str_replace(
                "\xC2\xA0",
                ' ',
                $value
            );

        $value =
            preg_replace(
                '/[ \t]+/',
                ' ',
                $value
            );

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


    private function ExtractProgram(
        string $text
    ): ?string {
        /*
         * Die bisherige Statusanzeige ist ungefähr:
         *
         * Energiemanagement
         * Hochsommer
         * PV ...
         *
         * Deshalb übernehmen wir die Zeile direkt nach
         * "Energiemanagement".
         */
        if (
            preg_match(
                '/Energiemanagement\s*\n\s*([^\n]+)/iu',
                $text,
                $match
            ) === 1
        ) {
            $program =
                trim(
                    $match[1]
                );

            /*
             * Sicherheitsprüfung:
             * Falls durch eine geänderte Quellformatierung
             * bereits die PV-Zeile getroffen würde.
             */
            if (
                !preg_match(
                    '/^(PV|Gesamtlimit|N[aä]chstes|PV-Prognose)\b/iu',
                    $program
                )
            ) {
                return $program;
            }
        }

        return null;
    }


    private function ParseEMSStatus(
        string $text
    ): array {
        $pv =
            $this->ExtractFloat(
                $text,
                '/\bPV\s+(-?\d+(?:[.,]\d+)?)\s*kW\b/i'
            );


        /*
         * Netz:
         * Einspeisung negativ,
         * Netzbezug positiv.
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


        $soc =
            $this->ExtractFloat(
                $text,
                '/\bSOC\s+(-?\d+(?:[.,]\d+)?)\s*%/i'
            );


        $limit =
            $this->ExtractFloat(
                $text,
                '/Gesamtlimit\s+(-?\d+(?:[.,]\d+)?)\s*kW/i'
            );


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


        $socNeed =
            $this->ExtractFloat(
                $text,
                '/SOC-Ladebedarf\s+(-?\d+(?:[.,]\d+)?)\s*kW/i'
            );


        $consumers =
            $this->ExtractInteger(
                $text,
                '/Verbraucher\s+(\d+)/i'
            );


        $forecastEnergy =
            $this->ExtractFloat(
                $text,
                '/PV-Prognose\s+(-?\d+(?:[.,]\d+)?)\s*kWh/i'
            );


        $forecastPeak =
            $this->ExtractFloat(
                $text,
                '/Peak\s+(-?\d+(?:[.,]\d+)?)\s*kW/i'
            );


        $forecastDuration =
            $this->ExtractFloat(
                $text,
                '/Dauer\s+(-?\d+(?:[.,]\d+)?)\s*h/i'
            );


        $program =
            $this->ExtractProgram(
                $text
            );


        return [
            'program' =>
                $program,

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


    private function SendLiveValues(): void
    {
        $text =
            $this->ReadEMSStatus();


        if ($text === '') {
            $payload = [
                'program' => null,

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


        $this->UpdateVisualizationValue(
            $json
        );
    }
}
