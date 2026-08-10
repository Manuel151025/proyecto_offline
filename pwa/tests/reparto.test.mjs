import test from 'node:test';
import assert from 'node:assert/strict';
import { repartirRespuesta } from '../js/sync.js';

/**
 * El reparto decide qué se reintenta y qué no.
 *
 * El fallo que motivó esto: el servidor abortaba el lote entero por un solo
 * registro inválido, y el cliente lo reenviaba en cada sincronización. Un
 * registro irreparable dejaba la cola bloqueada para siempre, arrastrando
 * consigo hasta 100 encuestas buenas.
 */

const item = (id, documento) => ({
  id,
  persona: { tipo_documento: 'CC', numero_documento: documento }
});

test('sin rechazos, todo se da por aceptado', () => {
  const pendientes = [item('enc-1', '1098765432'), item('enc-2', '1098765433')];

  const r = repartirRespuesta(pendientes, { rechazadas: [] });

  assert.deepEqual(r.idsAceptados, ['enc-1', 'enc-2']);
  assert.equal(r.rechazos.length, 0);
  assert.equal(r.clavesAceptadas.length, 2);
});

test('un rechazo no arrastra al resto del lote', () => {
  const pendientes = [
    item('enc-1', '1098765432'),
    item('enc-malo', 'hola'),
    item('enc-3', '1098765434')
  ];

  const r = repartirRespuesta(pendientes, {
    rechazadas: [{ id: 'enc-malo', motivo: 'El número de documento debe tener al menos 6 caracteres' }]
  });

  assert.deepEqual(r.idsAceptados, ['enc-1', 'enc-3']);
  assert.equal(r.rechazos.length, 1);
  assert.equal(r.rechazos[0].id, 'enc-malo');
});

test('la persona rechazada no se marca como sincronizada', () => {
  const pendientes = [item('enc-1', '1098765432'), item('enc-malo', 'hola')];

  const r = repartirRespuesta(pendientes, {
    rechazadas: [{ id: 'enc-malo', motivo: 'documento inválido' }]
  });

  // Si se marcara, la app la mostraría como subida cuando el servidor nunca
  // la aceptó, y el encuestador daría por bueno un dato que no existe.
  assert.deepEqual(r.clavesAceptadas, [['CC', '1098765432']]);
});

test('todo el lote rechazado no deja nada por reintentar', () => {
  const pendientes = [item('enc-a', 'hola'), item('enc-b', 'chao')];

  const r = repartirRespuesta(pendientes, {
    rechazadas: [
      { id: 'enc-a', motivo: 'documento inválido' },
      { id: 'enc-b', motivo: 'documento inválido' }
    ]
  });

  assert.deepEqual(r.idsAceptados, []);
  assert.deepEqual(r.clavesAceptadas, []);
  assert.equal(r.rechazos.length, 2);
});

/**
 * Durante un despliegue conviven el servidor viejo y el nuevo. Si el campo no
 * viene, no se puede deducir que todo fue rechazado: hay que aceptar, que es
 * como se comportaba antes.
 */
test('un servidor que aún no envía rechazadas se trata como todo aceptado', () => {
  const pendientes = [item('enc-1', '1098765432')];

  for (const respuesta of [{}, { rechazadas: undefined }, null, undefined]) {
    const r = repartirRespuesta(pendientes, respuesta);
    assert.deepEqual(r.idsAceptados, ['enc-1']);
    assert.equal(r.rechazos.length, 0);
  }
});
