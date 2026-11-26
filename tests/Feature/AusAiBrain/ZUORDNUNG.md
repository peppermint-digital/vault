# Vault-Tests aus AI Brain: wohin sie gehören

Stand: AI Brain `118ee2b2`, 26.11.2026. Es sind 42 Dateien, die Vault-Klassen berühren (`VaultService`, `VaultScope`, `VaultTrash`, `VaultVersion`, `VaultSpace`, `NoteShare`, `SharedVault`).

Spalte **Wohin**:
- **Paket**: Die Fälle sind hier übertragen und gegen die Paket-Schnittstelle geschrieben, oder sie stecken schon in `tests/Feature/*Test.php`. Für V2 sind sie der Riegel, dass das Verhalten gleich bleibt.
- **AI Brain**: Der Test prüft etwas, das nicht ins Paket gehört (Plan §3.2), und bleibt beim Modul.
- **später**: Der Baustein kommt erst in einem späteren Teil von V1b ins Paket; der Test zieht dann mit.

| AI-Brain-Test | Wohin | Wo im Paket |
|---|---|---|
| VaultServiceTest | Paket | AusAiBrain/VaultServiceTest, AblageTest, VaultTest, VerweiseTest |
| VaultPapierkorbTest | Paket | AusAiBrain/VaultPapierkorbTest, VaultTest, HttpTest |
| VaultVersionsTest | Paket | AusAiBrain/VaultVersionenTest, HttpTest |
| VaultVersioningTest | Paket | AusAiBrain/VaultVersionenTest, VaultTest |
| VaultBinaryVersionTest | Paket | AusAiBrain/VaultVersionenTest, VaultTest |
| VaultTextWegSchreibtKeineBinaerdateiTest | Paket | AusAiBrain/VaultTextwegTest (ohne den Fall „externe Quelle“, siehe SMB) |
| VaultVerborgeneWurzeldateienTest | Paket | AusAiBrain/VaultTextwegTest, VaultTest |
| VaultSpaceTest | Paket (Rechte und Trennung) / AI Brain (Räume anlegen und verwalten, Seed des globalen Raums) | AusAiBrain/VaultRaeumeUndSchieneTest, RechteTest |
| VaultUnifiedTest | Paket (JSON-Schiene) / AI Brain (Inertia-Seite, Scope-Tabs, Vorschläge) | AusAiBrain/VaultRaeumeUndSchieneTest, HttpTest |
| NoteShareTest | Paket (ohne das Umschreiben von `/notes/raw`- und `/vault/raw`-Adressen aus md2html) | AusAiBrain/VaultFreigabenTest, OeffentlicheFreigabeTest, HttpTest |
| SharedVaultTest | AI Brain | Altbau `SharedVaultController`, entfällt in V6 |
| VaultOfficeDateiAnlegenTest | später | mit EuroOffice (Blankovorlagen) |
| EuroOfficeCallbackTest | später | mit EuroOffice |
| VaultExterneQuelleTest | später | mit SMB-Quellen |
| AnnotationControllerTest, PdfEpubAnnotationTest | später | mit PDF/EPUB-Anmerkungen |
| VaultDeleteMoveToolsTest, Mcp/VaultToolsAuthScopeTest, Mcp/WriteSharedNoteToolTest, SharedVaultMcpAccessTest | AI Brain, die bereichsneutralen Teile später | MCP-Bausteine |
| VaultInitOnUserCreatedTest | AI Brain | Persona und Startdateien hängen an `KontoAngelegt`; das Ereignis selbst prüft VaultTest |
| OAuthClientVaultSpaceTest, ChannelVaultAccessTest, AvatarUserRestrictionTest, MandatoryChannelsProvisioningTest | AI Brain | Kanäle, Avatare, OAuth |
| Plugins/* (Calendar, VaultTasks, Nutrition, Companion), Mail/MailErfassenSpeicherTest, AgentChatLoeschenTest, BrowserAdresseZuReverbTest, Riegel/NamenloseDrosselnTest, TestpfadeGehoerenDemLaufTest | AI Brain | nutzen den Vault nur als Ablage; nach V2 laufen sie über die Paket-Schnittstelle und sind damit der zweite Riegel |

In V2 müssen alle Tests der Zeilen **AI Brain** in AI Brain grün bleiben, ohne dass man sie ändert. Ein Test, der dort angepasst werden muss, ist ein Hinweis auf eine Lücke im Paket.
