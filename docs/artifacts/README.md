# Artefak Sprint 1

Sumber PlantUML ini direkonstruksi dari implementasi Sprint 1 dan SRS pada 24 Oktober 2026. Diagram tidak diklaim sebagai file sumber asli penulis SRS; atribusi dan dokumen referensi sebelumnya tetap dipertahankan. Owner fitur mereview kecocokannya sebelum acceptance.

- auth/: activity, sequence boundary/control/entity, dan GUI login.
- uc01/: activity, sequence boundary/control/entity, dan GUI analisis.
- shared/: use case, komponen dan model data/class Sprint 1.

GUI Salt mendokumentasikan kontrol dan keadaan aplikasi; bukan pengganti desain visual asli. Spec/story/scenario dan traceability ada pada docs/SPRINT1_CLOSURE.md. PDF acuan tetap pada docs/references/.

Render: java -jar plantuml.jar -tsvg docs/artifacts/auth/*.puml docs/artifacts/uc01/*.puml docs/artifacts/shared/*.puml
