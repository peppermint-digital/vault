<?php

namespace Peppermint\Vault\Verknuepfung;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Peppermint\Vault\Ablageregeln;
use Peppermint\Vault\Bereich;
use Peppermint\Vault\Contracts\Verknuepfbar;
use Peppermint\Vault\Models\VaultFile;
use Peppermint\Vault\Models\VaultLink;
use Peppermint\Vault\Vault;

/**
 * Dateien an Objekte haengen, loesen, auflisten — und „hier ablegen".
 */
class Verknuepfungen
{
    public function __construct(
        private readonly Vault $vault,
        private readonly Ablageregeln $regeln,
    ) {}

    public function verknuepfen(
        VaultFile $datei,
        Model&Verknuepfbar $objekt,
        string $rolle = VaultLink::ROLLE_NOTIZ,
        ?Authenticatable $von = null,
        bool $angeheftet = false,
    ): VaultLink {
        return VaultLink::query()->firstOrCreate(
            [
                'file_id' => $datei->id,
                'linkable_type' => $objekt->getMorphClass(),
                'linkable_id' => (string) $objekt->getKey(),
                'rolle' => $rolle,
            ],
            ['angeheftet' => $angeheftet, 'user_id' => $von?->getAuthIdentifier()],
        );
    }

    public function loesen(VaultFile $datei, Model&Verknuepfbar $objekt, ?string $rolle = null): int
    {
        return VaultLink::query()
            ->where('file_id', $datei->id)
            ->where('linkable_type', $objekt->getMorphClass())
            ->where('linkable_id', (string) $objekt->getKey())
            ->when($rolle !== null, fn ($q) => $q->where('rolle', $rolle))
            ->delete();
    }

    /**
     * Die Dateien eines Objekts, angeheftete zuerst. Geloeschte (im
     * Papierkorb) bleiben aussen vor.
     *
     * @return Collection<int, VaultFile>
     */
    public function dateienVon(Model&Verknuepfbar $objekt, ?string $rolle = null): Collection
    {
        $links = $objekt->vaultLinks()
            ->when($rolle !== null, fn ($q) => $q->where('rolle', $rolle))
            ->orderByDesc('angeheftet')
            ->orderBy('id')
            ->get();

        $dateien = VaultFile::query()
            ->whereIn('id', $links->pluck('file_id'))
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        return new Collection($links->map(fn (VaultLink $l) => $dateien->get($l->file_id))->filter()->unique('id')->values()->all());
    }

    /**
     * Legt eine neue Textdatei am Ort der Ablage-Regel des Objekts an und
     * verknuepft sie. Gibt es den Namen schon, wird angehaengt (`-1`, `-2`).
     */
    public function hierAblegen(
        Model&Verknuepfbar $objekt,
        string $dateiname,
        string $inhalt,
        ?Authenticatable $von = null,
        string $rolle = VaultLink::ROLLE_NOTIZ,
    ): VaultFile {
        $regel = $this->regeln->fuer($objekt)
            ?? throw new InvalidArgumentException('Keine Ablage-Regel fuer '.$objekt->getMorphClass().'.');

        $bereich = $regel->bereichFuer($objekt);
        $pfad = $this->freierPfad($bereich, trim($regel->ordnerFuer($objekt), '/'), $dateiname);

        $this->vault->schreiben($bereich, $pfad, $inhalt, $this->autor($von));

        $datei = VaultFile::query()->findOrFail($this->vault->kennung()->sicherstellen($bereich, $pfad));
        $this->verknuepfen($datei, $objekt, $rolle, $von);

        return $datei;
    }

    /**
     * Das Objekt heisst jetzt anders: Der Ordner der Ablage-Regel zieht nach.
     * Nichts zu tun, wenn es den alten Ordner nicht gibt oder der neue
     * schon belegt ist (dann bleibt alles, wo es ist — kein Zusammenfuehren).
     */
    public function ordnerNachziehen(Model&Verknuepfbar $objekt, string $alterOrdner): bool
    {
        $regel = $this->regeln->fuer($objekt);
        if ($regel === null) {
            return false;
        }

        $bereich = $regel->bereichFuer($objekt);
        $neu = trim($regel->ordnerFuer($objekt), '/');
        $alt = trim($alterOrdner, '/');

        if ($alt === $neu || ! $this->vault->existiert($bereich, $alt) || $this->vault->existiert($bereich, $neu)) {
            return false;
        }

        $this->vault->verschieben($bereich, $alt, $neu);

        return true;
    }

    private function freierPfad(Bereich $bereich, string $ordner, string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $praefix = $ordner === '' ? '' : $ordner.'/';
        $stamm = pathinfo($name, PATHINFO_FILENAME);
        $endung = pathinfo($name, PATHINFO_EXTENSION);
        $anhang = $endung !== '' ? '.'.$endung : '';

        $kandidat = $praefix.$name;
        for ($i = 1; $this->vault->existiert($bereich, $kandidat); $i++) {
            $kandidat = "{$praefix}{$stamm}-{$i}{$anhang}";
        }

        return $kandidat;
    }

    private function autor(?Authenticatable $von): ?string
    {
        if ($von === null) {
            return null;
        }

        $name = $von->name ?? null;

        return is_string($name) && $name !== '' ? $name : (string) $von->getAuthIdentifier();
    }
}
