# GUID-Register SymconPellematic

Dieses Register ist **IMMUTABEL**. Ergaenzen ist erlaubt, das Aendern einer
bestehenden Zeile nicht. Eine einmal vergebene GUID bleibt fuer immer bei
ihrem Zweck, sonst verlieren bereits angelegte Instanzen ihr Modul und der
Kernel meldet nur noch Status 105.

| Prefix | Zweck                | GUID                                     | ModuleType |
|--------|----------------------|------------------------------------------|------------|
| -      | Library Pellematic   | {18EC59BE-A26E-492F-8855-B07B916AC7CB}   | -          |
| OKP    | Pellematic           | {ECC257D1-4FD5-4593-9461-F8AA76DD623C}   | 3          |
| -      | Reserve (zweites Modul, noch nicht vergeben) | {490D0227-8AF3-42EF-9494-5E5E7B256BB6} | - |

## Fremd-GUIDs

Diese GUIDs gehoeren nicht diesem Repo. Sie stehen hier, damit sie im Code als
Konstante an genau einer Stelle liegen und nicht verstreut werden.

| Zweck                        | GUID                                     | Hier                 |
|------------------------------|------------------------------------------|----------------------|
| IP-Symcon Archivsteuerung    | {43192F0B-135B-4CE7-A0A7-1475603F3060}   | Instanz #<ID>       |

## Prefix

`OKP` wurde gegen alle Modulordner unter `/var/lib/symcon/modules` geprueft und
ist kernelweit frei. Er ergibt die Funktionen `OKP_Poll`, `OKP_TestRead`,
`OKP_Discover`, `OKP_FillMapping`, `OKP_CheckMapping`, `OKP_Compare`,
`OKP_ExportCsv`, `OKP_Adopt`, `OKP_WriteTest`, `OKP_SetValueByKey`,
`OKP_GetValueByKey`.

## Profilnamen

Eigene Profile sind mit `OKP.` benannt, damit sie nie mit den seit 2024
bestehenden `oeko_*`-Profilen kollidieren. Die bestehenden Profile werden
weiterverwendet und niemals ueberschrieben.
