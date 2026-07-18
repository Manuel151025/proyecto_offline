package com.minsalud.encuestas.data.local.seed

import com.minsalud.encuestas.data.local.entity.MunicipioEntity

/**
 * Semilla offline-first de municipios de Colombia (DIVIPOLA/DANE): los 33
 * departamentos con su capital y principales municipios. Se inserta en Room
 * cuando la tabla local está vacía, para que el formulario funcione sin red.
 * Debe mantenerse alineada con database/schema.sql.
 */
object MunicipiosSeed {

    private fun m(codigo: String, nombre: String, departamento: String) =
        MunicipioEntity(codigo = codigo, nombre = nombre, departamento = departamento)

    val data: List<MunicipioEntity> = listOf(
        m("11001", "Bogotá D.C.", "Bogotá D.C."),
        // Antioquia
        m("05001", "Medellín", "Antioquia"),
        m("05088", "Bello", "Antioquia"),
        m("05360", "Itagüí", "Antioquia"),
        m("05266", "Envigado", "Antioquia"),
        m("05631", "Sabaneta", "Antioquia"),
        m("05129", "Caldas", "Antioquia"),
        m("05212", "Copacabana", "Antioquia"),
        m("05615", "Rionegro", "Antioquia"),
        m("05045", "Apartadó", "Antioquia"),
        m("05837", "Turbo", "Antioquia"),
        // Atlántico
        m("08001", "Barranquilla", "Atlántico"),
        m("08758", "Soledad", "Atlántico"),
        m("08433", "Malambo", "Atlántico"),
        m("08573", "Puerto Colombia", "Atlántico"),
        m("08296", "Galapa", "Atlántico"),
        m("08078", "Baranoa", "Atlántico"),
        m("08638", "Sabanalarga", "Atlántico"),
        // Bolívar
        m("13001", "Cartagena", "Bolívar"),
        m("13430", "Magangué", "Bolívar"),
        m("13836", "Turbaco", "Bolívar"),
        m("13052", "Arjona", "Bolívar"),
        m("13244", "El Carmen de Bolívar", "Bolívar"),
        // Boyacá
        m("15001", "Tunja", "Boyacá"),
        m("15238", "Duitama", "Boyacá"),
        m("15759", "Sogamoso", "Boyacá"),
        m("15176", "Chiquinquirá", "Boyacá"),
        m("15516", "Paipa", "Boyacá"),
        m("15407", "Villa de Leyva", "Boyacá"),
        // Caldas
        m("17001", "Manizales", "Caldas"),
        m("17873", "Villamaría", "Caldas"),
        m("17174", "Chinchiná", "Caldas"),
        m("17380", "La Dorada", "Caldas"),
        m("17614", "Riosucio", "Caldas"),
        m("17042", "Anserma", "Caldas"),
        // Caquetá
        m("18001", "Florencia", "Caquetá"),
        m("18753", "San Vicente del Caguán", "Caquetá"),
        m("18247", "El Doncello", "Caquetá"),
        m("18094", "Belén de los Andaquíes", "Caquetá"),
        // Cauca
        m("19001", "Popayán", "Cauca"),
        m("19698", "Santander de Quilichao", "Cauca"),
        m("19573", "Puerto Tejada", "Cauca"),
        m("19532", "Patía", "Cauca"),
        m("19455", "Miranda", "Cauca"),
        // Cesar
        m("20001", "Valledupar", "Cesar"),
        m("20011", "Aguachica", "Cesar"),
        m("20060", "Bosconia", "Cesar"),
        m("20013", "Agustín Codazzi", "Cesar"),
        m("20238", "El Copey", "Cesar"),
        // Córdoba
        m("23001", "Montería", "Córdoba"),
        m("23417", "Lorica", "Córdoba"),
        m("23660", "Sahagún", "Córdoba"),
        m("23162", "Cereté", "Córdoba"),
        m("23555", "Planeta Rica", "Córdoba"),
        m("23466", "Montelíbano", "Córdoba"),
        m("23807", "Tierralta", "Córdoba"),
        // Chocó
        m("27001", "Quibdó", "Chocó"),
        m("27361", "Istmina", "Chocó"),
        m("27787", "Tadó", "Chocó"),
        m("27205", "Condoto", "Chocó"),
        // Cundinamarca
        m("25754", "Soacha", "Cundinamarca"),
        m("25290", "Fusagasugá", "Cundinamarca"),
        m("25899", "Zipaquirá", "Cundinamarca"),
        m("25175", "Chía", "Cundinamarca"),
        m("25269", "Facatativá", "Cundinamarca"),
        m("25307", "Girardot", "Cundinamarca"),
        m("25473", "Mosquera", "Cundinamarca"),
        m("25430", "Madrid", "Cundinamarca"),
        m("25286", "Funza", "Cundinamarca"),
        // Huila
        m("41001", "Neiva", "Huila"),
        m("41551", "Pitalito", "Huila"),
        m("41298", "Garzón", "Huila"),
        m("41396", "La Plata", "Huila"),
        m("41132", "Campoalegre", "Huila"),
        // La Guajira
        m("44001", "Riohacha", "La Guajira"),
        m("44430", "Maicao", "La Guajira"),
        m("44847", "Uribia", "La Guajira"),
        m("44560", "Manaure", "La Guajira"),
        m("44279", "Fonseca", "La Guajira"),
        m("44650", "San Juan del Cesar", "La Guajira"),
        // Magdalena
        m("47001", "Santa Marta", "Magdalena"),
        m("47189", "Ciénaga", "Magdalena"),
        m("47288", "Fundación", "Magdalena"),
        m("47245", "El Banco", "Magdalena"),
        m("47555", "Plato", "Magdalena"),
        m("47980", "Zona Bananera", "Magdalena"),
        // Meta
        m("50001", "Villavicencio", "Meta"),
        m("50006", "Acacías", "Meta"),
        m("50313", "Granada", "Meta"),
        m("50573", "Puerto López", "Meta"),
        m("50689", "San Martín", "Meta"),
        // Nariño
        m("52001", "Pasto", "Nariño"),
        m("52356", "Ipiales", "Nariño"),
        m("52835", "Tumaco", "Nariño"),
        m("52480", "La Unión", "Nariño"),
        m("52838", "Túquerres", "Nariño"),
        m("52678", "Samaniego", "Nariño"),
        // Norte de Santander
        m("54001", "Cúcuta", "Norte de Santander"),
        m("54874", "Villa del Rosario", "Norte de Santander"),
        m("54405", "Los Patios", "Norte de Santander"),
        m("54498", "Ocaña", "Norte de Santander"),
        m("54518", "Pamplona", "Norte de Santander"),
        // Quindío
        m("63001", "Armenia", "Quindío"),
        m("63130", "Calarcá", "Quindío"),
        m("63401", "La Tebaida", "Quindío"),
        m("63470", "Montenegro", "Quindío"),
        m("63594", "Quimbaya", "Quindío"),
        // Risaralda
        m("66001", "Pereira", "Risaralda"),
        m("66170", "Dosquebradas", "Risaralda"),
        m("66682", "Santa Rosa de Cabal", "Risaralda"),
        m("66400", "La Virginia", "Risaralda"),
        // Santander
        m("68001", "Bucaramanga", "Santander"),
        m("68276", "Floridablanca", "Santander"),
        m("68307", "Girón", "Santander"),
        m("68547", "Piedecuesta", "Santander"),
        m("68081", "Barrancabermeja", "Santander"),
        m("68679", "San Gil", "Santander"),
        // Sucre
        m("70001", "Sincelejo", "Sucre"),
        m("70215", "Corozal", "Sucre"),
        m("70820", "Santiago de Tolú", "Sucre"),
        m("70708", "San Marcos", "Sucre"),
        m("70670", "Sampués", "Sucre"),
        // Tolima
        m("73001", "Ibagué", "Tolima"),
        m("73268", "Espinal", "Tolima"),
        m("73349", "Honda", "Tolima"),
        m("73411", "Líbano", "Tolima"),
        m("73168", "Chaparral", "Tolima"),
        m("73449", "Melgar", "Tolima"),
        // Valle del Cauca
        m("76001", "Cali", "Valle del Cauca"),
        m("76109", "Buenaventura", "Valle del Cauca"),
        m("76520", "Palmira", "Valle del Cauca"),
        m("76834", "Tuluá", "Valle del Cauca"),
        m("76147", "Cartago", "Valle del Cauca"),
        m("76364", "Jamundí", "Valle del Cauca"),
        m("76892", "Yumbo", "Valle del Cauca"),
        m("76111", "Guadalajara de Buga", "Valle del Cauca"),
        // Arauca
        m("81001", "Arauca", "Arauca"),
        m("81736", "Saravena", "Arauca"),
        m("81794", "Tame", "Arauca"),
        m("81300", "Fortul", "Arauca"),
        // Casanare
        m("85001", "Yopal", "Casanare"),
        m("85010", "Aguazul", "Casanare"),
        m("85440", "Villanueva", "Casanare"),
        m("85410", "Tauramena", "Casanare"),
        m("85250", "Paz de Ariporo", "Casanare"),
        // Putumayo
        m("86001", "Mocoa", "Putumayo"),
        m("86568", "Puerto Asís", "Putumayo"),
        m("86320", "Orito", "Putumayo"),
        m("86865", "Valle del Guamuez", "Putumayo"),
        m("86749", "Sibundoy", "Putumayo"),
        // Archipiélago de San Andrés, Providencia y Santa Catalina
        m("88001", "San Andrés", "Archipiélago de San Andrés, Providencia y Santa Catalina"),
        m("88564", "Providencia", "Archipiélago de San Andrés, Providencia y Santa Catalina"),
        // Amazonas
        m("91001", "Leticia", "Amazonas"),
        m("91540", "Puerto Nariño", "Amazonas"),
        // Guainía
        m("94001", "Inírida", "Guainía"),
        // Guaviare
        m("95001", "San José del Guaviare", "Guaviare"),
        m("95025", "El Retorno", "Guaviare"),
        m("95040", "Miraflores", "Guaviare"),
        // Vaupés
        m("97001", "Mitú", "Vaupés"),
        // Vichada
        m("99001", "Puerto Carreño", "Vichada"),
        m("99524", "La Primavera", "Vichada"),
        m("99773", "Cumaribo", "Vichada")
    )
}
