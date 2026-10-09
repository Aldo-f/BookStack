# Peppol Prijs Calculator — Kleine Zelfstandige

## Formulier Logica (voor integratie in docs)

### Input Velden

| Veld | Type | Opties / Eenheid |
|------|------|-------------------|
| Aantal facturen per kwartaal | Getal | 1 – 500 |
| Bijberoep (checkbox) | Boolean | Aan / Uit |
| Met facturatieprogramma (checkbox) | Boolean | Aan / Uit |

### Berekening Logica

```python
# Per kwartaal → per jaar × 4
aantal_jaar = aantal_per_kwartaal * 4

# Model selectie
if bijberoep and aantal_jaar <= 20:
    model = "Gratis (Accountable / Dexxter)"
    kost_jaar = 0
elif bijberoep and aantal_jaar <= 60:
    model = "Pay-per-use (e-invoice.be)"
    kost_jaar = aantal_jaar * 0.50  # 0.25 verzend + 0.25 ontvang
elif met_facturatieprogramma:
    model = "Abonnement (Billit / Yuki / Accountable Pro)"
    kost_jaar = 180  # ~€15/maand
else:
    model = "Pay-per-use (e-invoice.be)"
    kost_jaar = aantal_jaar * 0.50

# Per-factuur kost
kost_per_factuur = kost_jaar / (aantal_jaar * 2)  # in + uit
```

### Indicatieve Prijs per Factuur (in + uit gecombineerd)

| Facturen/jaar | Bijberoep? | Facturatieprog? | Aanbevolen model | Jaarprijs | Per doc (€) |
|---|---|---|---|---|---|
| 4 | ✓ | — | Gratis (Accountable) | €0 | €0 |
| 4 | ✓ | ✓ | Abonnement | €180 | €11,25 |
| 4 | ✗ | ✓ | Abonnement (Billit) | €180 | €11,25 |
| 12 | ✓ | — | Pay-per-use | €6 | €0,25 |
| 12 | ✗ | — | Pay-per-use | €6 | €0,25 |
| 12 | ✓ | ✓ | Abonnement | €180 | €3,75 |
| 60 | ✗ | — | Pay-per-use | €30 | €0,25 |
| 60 | ✗ | ✓ | Abonnement (Billit) | €180 | €1,50 |
| 60 | ✗ | ✓ | Flexina (compleet) | €240 | €2,00 |
| 240 | ✗ | — | Abonnement + volume | €360 | €0,75 |

### Aanbeveling Per Scenario

**Casus A: 4/kwartaal (16/jaar) — Bijberoep, geen boekhouding**
- ✅ **Gratis** — Accountable (bijberoep gratis) of Dexxter basis
- 💡 Geen abonnement nodig, Peppol inbegrepen

**Casus B: 4/kwartaal (16/jaar) — Met boekhouding**
- ✅ **Billit / Accountable Pro** — ~€15/maand = €180/jaar
- 💡 Bij 16 facturen = €5,63/doc; bij 120% aftrek = €216 aftrekbaar

**Casus C: 15/kwartaal (60/jaar) — Hoofdberoep, geen boekhouding**
- ✅ **Pay-per-use** — e-invoice.be = €30/jaar (€0,25/doc)
- 💡 Goedkoper dan abonnement tot ~40 facturen/jaar

**Casus D: 15/kwartaal (60/jaar) — Met boekhouding**
- ✅ **Billit / Flexina** — €180-240/jaar
- 💡 Breekpunt: abonnement rendabel vanaf ~30-40/jaar als je boekhouding nodig hebt

### HTML/Vorm Template (voor integratie in docs pagina)

```html
<form id="peppol-calc" onsubmit="event.preventDefault(); bereken();">
  <label>Aantal facturen per kwartaal:
    <input type="number" id="kwartaal" value="4" min="1" max="500">
  </label>
  <label><input type="checkbox" id="bijberoep" checked> Bijberoep</label>
  <label><input type="checkbox" id="facturatie" checked> Met facturatieprogramma</label>
  <button onclick="bereken()">Bereken beste prijs</button>
  <div id="resultaat"></div>
</form>
```

### Bronnen voor Prijzen (voor SourceChecker)

- https://e-invoice.be/blog/kosten-peppol-e-facturatie (kostenoverzicht)
- https://e-invoice.be/blog/best-peppol-access-points (provider vergelijking)
- https://www.accountable.eu/nl-be/blog/beste-peppol-e-facturatiesoftware/ (prijsvergelijking)
- https://www.myflexina.com/en/electronic-invoicing-peppol/ (Flexina €19,99/maand)

### Notitie voor Documentatie

> De calculator geeft een **indicatie**, geen bindend advies. Werkelijke prijzen variëren per provider en kunnen veranderen. Controleer altijd de actuele tarieven op de officiële websites. De 120% fiscale aftrekbaarheid geldt voor alle beroepskosten gerelateerd aan e-facturatie tot en met 2027.
