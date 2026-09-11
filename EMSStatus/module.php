<?php

declare(strict_types=1);

class EMSStatus extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        /*
         * Datenpunkte.
         * Vorerst über die Instanz konfigurierbar,
         * damit keine falschen IDs fest eingebaut werden.
         */

        $this->RegisterPropertyInteger('PVPowerID', 0);
        $this->RegisterPropertyInteger('GridPowerID', 0);
        $this->RegisterPropertyInteger('SOCID', 0);
        $this->RegisterPropertyInteger('TotalLimitID', 0);

        $this->RegisterPropertyInteger('TargetTimeID', 0);
        $this->RegisterPropertyInteger('TargetSOCID', 0);
        $this->RegisterPropertyInteger('SOCPowerNeedID', 0);
        $this->RegisterPropertyInteger('ConsumerCountID', 0);

        $this->RegisterPropertyInteger('ForecastEnergyID', 0);
        $this->RegisterPropertyInteger('ForecastPeakID', 0);
        $this->RegisterPropertyInteger('ForecastDurationID', 0);

        /*
         * Native SDK-Visualisierung.
         * Dadurch wird nicht ständig das ganze HTML neu geladen.
         */

        $this->SetVisualizationType(1);
    }


    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        foreach ($this->GetObservedIDs() as $id) {
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage(
                    $id,
                    VM_UPDATE
                );
            }
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

        $html = file_get_contents($file);

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

        if ($Message === VM_UPDATE) {
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

        throw new Exception('Invalid Ident');
    }


    private function GetObservedIDs(): array
    {
        return [
            $this->ReadPropertyInteger('PVPowerID'),
            $this->ReadPropertyInteger('GridPowerID'),
            $this->ReadPropertyInteger('SOCID'),
            $this->ReadPropertyInteger('TotalLimitID'),

            $this->ReadPropertyInteger('TargetTimeID'),
            $this->ReadPropertyInteger('TargetSOCID'),
            $this->ReadPropertyInteger('SOCPowerNeedID'),
            $this->ReadPropertyInteger('ConsumerCountID'),

            $this->ReadPropertyInteger('ForecastEnergyID'),
            $this->ReadPropertyInteger('ForecastPeakID'),
            $this->ReadPropertyInteger('ForecastDurationID')
        ];
    }


    private function ReadSafe(
        int $id,
        mixed $default = null
    ): mixed {
        if ($id <= 0) {
            return $default;
        }

        if (!IPS_VariableExists($id)) {
            return $default;
        }

        return GetValue($id);
    }


    private function ReadFloat(
        int $id
    ): ?float {
        $value = $this->ReadSafe($id, null);

        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }


    private function ReadInteger(
        int $id
    ): ?int {
        $value = $this->ReadSafe($id, null);

        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }


    private function ReadString(
        int $id
    ): ?string {
        $value = $this->ReadSafe($id, null);

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }


    private function SendLiveValues(): void
    {
        $payload = [
            'pv' => $this->ReadFloat(
                $this->ReadPropertyInteger('PVPowerID')
            ),

            'grid' => $this->ReadFloat(
                $this->ReadPropertyInteger('GridPowerID')
            ),

            'soc' => $this->ReadFloat(
                $this->ReadPropertyInteger('SOCID')
            ),

            'limit' => $this->ReadFloat(
                $this->ReadPropertyInteger('TotalLimitID')
            ),

            'targetTime' => $this->ReadString(
                $this->ReadPropertyInteger('TargetTimeID')
            ),

            'targetSOC' => $this->ReadFloat(
                $this->ReadPropertyInteger('TargetSOCID')
            ),

            'socNeed' => $this->ReadFloat(
                $this->ReadPropertyInteger('SOCPowerNeedID')
            ),

            'consumers' => $this->ReadInteger(
                $this->ReadPropertyInteger('ConsumerCountID')
            ),

            'forecastEnergy' => $this->ReadFloat(
                $this->ReadPropertyInteger('ForecastEnergyID')
            ),

            'forecastPeak' => $this->ReadFloat(
                $this->ReadPropertyInteger('ForecastPeakID')
            ),

            'forecastDuration' => $this->ReadFloat(
                $this->ReadPropertyInteger('ForecastDurationID')
            )
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            return;
        }

        /*
         * Nur Werte aktualisieren.
         * Kein komplettes Neuladen der Kachel.
         */

        $this->UpdateVisualizationValue($json);
    }
}
