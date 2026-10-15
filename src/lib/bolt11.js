// ════════════════════════════════════════════════════════════════
//  BOLT11 — l'anatomie d'une facture Lightning
//
//  Une facture "lnbc1500n1p…" n'est PAS un identifiant opaque : c'est
//  un petit message signé, encodé en bech32 (comme les adresses
//  bc1…). On peut tout lire dedans, sans rien demander à personne :
//
//    lnbc 1500n 1 pjq…(données)…  xxxxxx
//    │    │     │ │                └ checksum bech32 (6 caractères)
//    │    │     │ └ données : horodatage + champs + signature
//    │    │     └ séparateur "1"
//    │    └ montant (1500 nano-BTC = 150 sats)
//    └ réseau : lnbc = mainnet, lntb = testnet, lnbcrt = regtest
//
//  Champs importants à l'intérieur :
//   • p  payment_hash  = sha256(preimage). Le payeur obtient la
//                         preimage en payant : c'est son REÇU.
//   • d  description
//   • x  expiration (secondes, 3600 par défaut)
//   • s  payment_secret (anti-sondage)
//   • signature du nœud qui a émis la facture → on en déduit sa
//     clé publique (l'identité du nœud destinataire)
//
//  Ce fichier est utilisé des DEUX côtés :
//   • navigateur : decoderBolt11() pour afficher l'anatomie
//   • serveur    : encoderBolt11() pour fabriquer de VRAIES factures
//                  signées en mode simulation
//  Il ne contient aucun secret.
//  Référence : https://github.com/lightning/bolts/blob/master/11-payment-encoding.md
// ════════════════════════════════════════════════════════════════

import { secp256k1 } from '@noble/curves/secp256k1'
import { sha256 } from '@noble/hashes/sha2'
import { bytesToHex, hexToBytes, utf8ToBytes } from '@noble/hashes/utils'

// ── bech32 : l'alphabet de 32 caractères (5 bits chacun) ───────
const ALPHABET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l'

function polymod(valeurs) {
  const G = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3]
  let chk = 1
  for (const v of valeurs) {
    const b = chk >> 25
    chk = ((chk & 0x1ffffff) << 5) ^ v
    for (let i = 0; i < 5; i++) if ((b >> i) & 1) chk ^= G[i]
  }
  return chk
}
const etendreHrp = (hrp) => [...hrp].map((c) => c.charCodeAt(0) >> 5)
  .concat([0], [...hrp].map((c) => c.charCodeAt(0) & 31))

const checksumValide = (hrp, mots) => polymod(etendreHrp(hrp).concat(mots)) === 1
function calculerChecksum(hrp, mots) {
  const mod = polymod(etendreHrp(hrp).concat(mots, [0, 0, 0, 0, 0, 0])) ^ 1
  return Array.from({ length: 6 }, (_, i) => (mod >> (5 * (5 - i))) & 31)
}

// Conversion entre "mots" de 5 bits et octets de 8 bits
function convertirBits(donnees, de, vers, completer) {
  let acc = 0, bits = 0
  const sortie = []
  const max = (1 << vers) - 1
  for (const v of donnees) {
    acc = (acc << de) | v
    bits += de
    while (bits >= vers) { bits -= vers; sortie.push((acc >> bits) & max) }
  }
  if (completer && bits > 0) sortie.push((acc << (vers - bits)) & max)
  return sortie
}
const motsVersOctets = (mots) => Uint8Array.from(convertirBits(mots, 5, 8, false))
const octetsVersMots = (octets) => convertirBits(octets, 8, 5, true)
const motsVersEntier = (mots) => mots.reduce((n, m) => n * 32 + m, 0)
const entierVersMots = (n, longueur) => {
  const mots = []
  for (let i = 0; i < longueur; i++) { mots.unshift(n % 32); n = Math.floor(n / 32) }
  return mots
}

// ── Réseaux et montants ────────────────────────────────────────
const RESEAUX = { bc: 'mainnet (vrai bitcoin)', tb: 'testnet', bcrt: 'regtest (réseau local)', tbs: 'signet' }
// Le montant est exprimé en BTC, avec un multiplicateur :
//   m = milli (0,001) · u = micro · n = nano · p = pico
const MSATS_PAR_UNITE = { m: 100_000_000n, u: 100_000n, n: 100n, p: 0.1 }

function lireMontant(texte) {
  if (!texte) return null // facture "montant libre"
  const m = texte.match(/^(\d+)([munp]?)$/)
  if (!m) throw new Error(`montant illisible : ${texte}`)
  const [, chiffres, unite] = m
  if (!unite) return BigInt(chiffres) * 100_000_000_000n // en BTC entiers
  if (unite === 'p') {
    if (!chiffres.endsWith('0')) throw new Error('montant en pico-BTC non multiple de 10')
    return BigInt(chiffres) / 10n
  }
  return BigInt(chiffres) * MSATS_PAR_UNITE[unite]
}

// Les champs "étiquetés" (tagged fields) qu'on sait nommer
const CHAMPS = {
  p: 'payment_hash', s: 'payment_secret', d: 'description', h: 'description_hash',
  x: 'expiration', c: 'min_final_cltv_expiry', n: 'pubkey du nœud', f: 'adresse de repli',
  r: 'indice de routage', 9: 'features', m: 'métadonnées',
}

// ════════════════════════════════════════════════════════════════
//  decoderBolt11(facture)
//  Retourne tout ce qu'on peut lire dans la facture, plus le
//  découpage en morceaux (pour la colorer dans l'interface).
//  Lève une erreur si ce n'est pas une facture valide.
// ════════════════════════════════════════════════════════════════
export function decoderBolt11(facture) {
  const brut = String(facture || '').trim().replace(/^lightning:/i, '')
  if (brut !== brut.toLowerCase() && brut !== brut.toUpperCase()) throw new Error('casse mélangée')
  const texte = brut.toLowerCase()

  // 1) hrp ("human readable part") et données, séparés par le DERNIER "1"
  const sep = texte.lastIndexOf('1')
  if (sep < 1 || !texte.startsWith('ln')) throw new Error('ce n\'est pas une facture Lightning (doit commencer par ln…)')
  const hrp = texte.slice(0, sep)
  const mots = [...texte.slice(sep + 1)].map((c) => {
    const v = ALPHABET.indexOf(c)
    if (v === -1) throw new Error(`caractère interdit en bech32 : « ${c} »`)
    return v
  })
  if (!checksumValide(hrp, mots)) throw new Error('checksum bech32 invalide (facture tronquée ou modifiée ?)')

  // 2) réseau + montant dans le hrp
  const h = hrp.match(/^ln(bcrt|tbs|bc|tb)(\d*[munp]?)$/)
  if (!h) throw new Error(`préfixe inconnu : ${hrp}`)
  const [, codeReseau, texteMontant] = h
  const msats = lireMontant(texteMontant)

  // 3) données = horodatage (7 mots) + champs + signature (104 mots) + checksum (6)
  const donnees = mots.slice(0, -6)
  const motsSignature = donnees.slice(-104)
  const motsSignes = donnees.slice(0, -104)
  const horodatage = motsVersEntier(motsSignes.slice(0, 7))

  const champs = []
  let i = 7
  while (i < motsSignes.length) {
    const code = ALPHABET[motsSignes[i]]
    const longueur = motsSignes[i + 1] * 32 + motsSignes[i + 2]
    const valeur = motsSignes.slice(i + 3, i + 3 + longueur)
    champs.push({ code, nom: CHAMPS[code] || `champ inconnu « ${code} »`, mots: valeur, debut: i, fin: i + 3 + longueur })
    i += 3 + longueur
  }
  const champ = (code) => champs.find((c) => c.code === code)
  const enHex = (code) => (champ(code) ? bytesToHex(motsVersOctets(champ(code).mots)) : null)

  // 4) signature : 64 octets (r,s) + 1 octet "recovery id".
  //    Le message signé = sha256(hrp en UTF-8 + données en octets).
  //    Avec la recovery id, on RETROUVE la clé publique du nœud
  //    émetteur — pas besoin qu'elle soit écrite dans la facture.
  const sigOctets = motsVersOctets(motsSignature)
  const empreinte = sha256(new Uint8Array([...utf8ToBytes(hrp), ...Uint8Array.from(convertirBits(motsSignes, 5, 8, true))]))
  let noeud = enHex('n')
  let signatureValide = false
  try {
    const sig = secp256k1.Signature.fromCompact(sigOctets.slice(0, 64)).addRecoveryBit(sigOctets[64])
    const retrouvee = sig.recoverPublicKey(empreinte).toHex(true)
    signatureValide = !noeud || noeud === retrouvee
    noeud = noeud || retrouvee
  } catch { /* signature illisible : signatureValide reste false */ }

  const expiration = champ('x') ? motsVersEntier(champ('x').mots) : 3600 // défaut BOLT11
  const description = champ('d') ? new TextDecoder().decode(motsVersOctets(champ('d').mots)) : null

  // Positions (en caractères) de chaque morceau — pour la coloration
  const debutDonnees = sep + 1
  const pos = (motIndex) => debutDonnees + motIndex
  const morceaux = [
    { type: 'prefixe', texte: texte.slice(0, 2 + codeReseau.length), titre: `Préfixe : ln + réseau ${RESEAUX[codeReseau]}` },
    { type: 'montant', texte: texteMontant, titre: texteMontant ? `Montant : ${texteMontant}` : 'Pas de montant (montant libre)' },
    { type: 'separateur', texte: '1', titre: 'Séparateur bech32' },
    { type: 'horodatage', texte: texte.slice(pos(0), pos(7)), titre: 'Horodatage de création' },
    ...champs.map((c) => ({ type: `champ-${c.code}`, texte: texte.slice(pos(c.debut), pos(c.fin)), titre: `Champ ${c.code} : ${c.nom}` })),
    { type: 'signature', texte: texte.slice(pos(motsSignes.length), pos(donnees.length)), titre: 'Signature du nœud émetteur' },
    { type: 'checksum', texte: texte.slice(pos(donnees.length)), titre: 'Checksum bech32 (détecte les fautes de frappe)' },
  ].filter((m) => m.texte)

  return {
    reseau: codeReseau,
    reseauNom: RESEAUX[codeReseau],
    montantMsats: msats === null ? null : msats.toString(),
    montantSats: msats === null ? null : Number(msats) / 1000,
    creeLe: horodatage * 1000,
    expiration,
    expireLe: (horodatage + expiration) * 1000,
    expiree: Date.now() > (horodatage + expiration) * 1000,
    description,
    descriptionHash: enHex('h'),
    paymentHash: enHex('p'),
    paymentSecret: enHex('s'),
    noeud,
    signatureValide,
    champs: champs.map(({ code, nom }) => ({ code, nom })),
    morceaux,
  }
}

// ════════════════════════════════════════════════════════════════
//  verifierPreimage(preimageHex, paymentHashHex)
//  LA preuve de paiement Lightning : celui qui connaît une valeur
//  dont le sha256 donne le payment_hash a forcément payé (le nœud
//  destinataire ne la révèle qu'en échange des sats).
// ════════════════════════════════════════════════════════════════
export function verifierPreimage(preimageHex, paymentHashHex) {
  if (!/^[0-9a-f]{64}$/i.test(preimageHex || '') || !/^[0-9a-f]{64}$/i.test(paymentHashHex || '')) return false
  return bytesToHex(sha256(hexToBytes(preimageHex))) === paymentHashHex.toLowerCase()
}

// ════════════════════════════════════════════════════════════════
//  encoderBolt11({ reseau, montantSats, paymentHash, paymentSecret,
//                   description, expiration, clePrivee })
//  Sert au MODE SIMULATION : fabrique une vraie facture, bien signée,
//  que n'importe quel décodeur (ou wallet) sait lire. Elle n'est
//  payable par personne : le nœud "émetteur" n'existe pas.
// ════════════════════════════════════════════════════════════════
export function encoderBolt11({ reseau = 'bcrt', montantSats, paymentHash, paymentSecret, description = '', expiration = 3600, clePrivee }) {
  // Montant : on choisit l'unité la plus courte qui tombe juste
  const msats = BigInt(montantSats) * 1000n
  let texteMontant
  if (msats % 100_000_000n === 0n) texteMontant = `${msats / 100_000_000n}m`
  else if (msats % 100_000n === 0n) texteMontant = `${msats / 100_000n}u`
  else texteMontant = `${msats / 100n}n`
  const hrp = `ln${reseau}${texteMontant}`

  const champ = (code, mots) => [ALPHABET.indexOf(code), mots.length >> 5, mots.length & 31, ...mots]
  const entierEnMots = (n) => { // entier → le moins de mots possible
    const mots = []
    do { mots.unshift(n % 32); n = Math.floor(n / 32) } while (n > 0)
    return mots
  }

  const motsSignes = [
    ...entierVersMots(Math.floor(Date.now() / 1000), 7),
    ...champ('p', octetsVersMots(hexToBytes(paymentHash))),
    ...champ('s', octetsVersMots(hexToBytes(paymentSecret))),
    ...champ('d', octetsVersMots(utf8ToBytes(description))),
    ...champ('x', entierEnMots(expiration)),
  ]
  const empreinte = sha256(new Uint8Array([...utf8ToBytes(hrp), ...Uint8Array.from(convertirBits(motsSignes, 5, 8, true))]))
  const sig = secp256k1.sign(empreinte, clePrivee)
  const motsSignature = octetsVersMots(new Uint8Array([...sig.toCompactRawBytes(), sig.recovery]))

  const donnees = [...motsSignes, ...motsSignature]
  return hrp + '1' + [...donnees, ...calculerChecksum(hrp, donnees)].map((v) => ALPHABET[v]).join('')
}
