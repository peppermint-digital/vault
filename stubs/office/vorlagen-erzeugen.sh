#!/bin/bash
#
# Erzeugt die leeren Office-Vorlagen in diesem Verzeichnis neu (#6298).
#
# Warum eigene statt der Vorlagen aus dem Euro-Office-Container: AI Brain ist
# MIT-lizenziert, der Document Server AGPL. Ein leeres Dokument trägt keinen
# fremden Inhalt — damit stellt sich die Lizenzfrage gar nicht.
#
# Braucht LibreOffice und Python 3. Aufruf:
#
#   bash stubs/office/vorlagen-erzeugen.sh (im Paket peppermint/vault) /pfad/fuer/zwischenstaende
#
# Die erzeugten Dateien landen unter <arbeitsverzeichnis>/vorlagen/ und werden
# von dort nach stubs/office/ kopiert — absichtlich in zwei Schritten, damit ein
# fehlgeschlagener Lauf die eingecheckten Vorlagen nicht zerschießt.
set -u

ARBEIT="${1:?Arbeitsverzeichnis angeben}"
OUT="$ARBEIT/vorlagen"
PROFIL="$ARBEIT/lo-profil"

rm -rf "$OUT" "$PROFIL"
mkdir -p "$OUT"
cd "$OUT" || exit 1

LO=(soffice --headless --norestore "-env:UserInstallation=file://$PROFIL")

# --- Writer: eine leere Textdatei genügt -----------------------------------
: > leer.txt
timeout 180 "${LO[@]}" --convert-to docx leer.txt --outdir . >/dev/null 2>&1

# --- Calc: eine leere CSV öffnet Calc --------------------------------------
: > leer.csv
timeout 180 "${LO[@]}" --convert-to xlsx leer.csv --outdir . >/dev/null 2>&1

# LibreOffice nennt das Blatt beim xlsx-Export immer "Sheet1" — unabhängig vom
# Dateinamen. Der Name steht unten in JEDER neuen Tabelle; in einem deutschen
# Produkt sieht "Sheet1" aus wie ein Überbleibsel. Also schreiben wir ihn um:
# Es ist unsere eigene Datei.
python3 -c '
import shutil, sys, zipfile
ziel = sys.argv[1]
roh = ziel + ".roh"
shutil.move(ziel, roh)
with zipfile.ZipFile(roh) as alt, zipfile.ZipFile(ziel, "w", zipfile.ZIP_DEFLATED) as neu:
    for eintrag in alt.infolist():
        inhalt = alt.read(eintrag.filename)
        if eintrag.filename == "xl/workbook.xml":
            inhalt = inhalt.replace(b"name=\"Sheet1\"", b"name=\"Tabelle1\"")
        neu.writestr(eintrag, inhalt)
' "$OUT/leer.xlsx"
rm -f "$OUT/leer.xlsx.roh"

# --- Impress: flaches ODF, von Hand geschrieben ----------------------------
# Eine einzige leere Folie, sonst nichts. Ein leeres .txt landet in Writer,
# deshalb führt hier kein Weg über eine Textdatei.
cat > leer.fodp <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<office:document
  xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
  xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"
  office:version="1.3"
  office:mimetype="application/vnd.oasis.opendocument.presentation">
  <office:body>
    <office:presentation>
      <draw:page draw:name="Folie 1"/>
    </office:presentation>
  </office:body>
</office:document>
XML
timeout 180 "${LO[@]}" --convert-to pptx leer.fodp --outdir . >/dev/null 2>&1

# --- Gegenprobe -------------------------------------------------------------
echo "--- erzeugt ---"
FEHLER=0
for f in leer.docx leer.xlsx leer.pptx; do
    if [ ! -f "$f" ]; then
        echo "$f  FEHLT"
        FEHLER=1
        continue
    fi
    printf "%-12s %8s Bytes   " "$f" "$(stat -c%s "$f")"
    if unzip -l "$f" >/dev/null 2>&1; then
        echo "gültiges ZIP ($(unzip -l "$f" | tail -1 | awk '{print $2}') Dateien)"
    else
        echo "KEIN gültiges ZIP"
        FEHLER=1
    fi
done

exit "$FEHLER"
