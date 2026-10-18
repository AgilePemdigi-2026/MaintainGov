Feature: Analyze user feedback
  Sebagai anggota Tim Maintenance,
  saya ingin mengunggah CSV feedback pengguna dan mendapatkan hasil analisis,
  agar saya dapat mengelola maintenance case berdasarkan feedback nyata.

  Scenario: Valid feedback produces persisted pending cases
    Given I am authenticated as a Maintenance Team member
    And a reviewed CSV fixture contains actionable feedback
    When I upload the CSV and start analysis
    Then the analysis batch eventually has status "Selesai"
    And the generated Maintenance Cases have status "Pending"
    And each case is linked to its source reviews
    When I reload the result page
    Then the persisted results are still displayed

  Scenario: Invalid CSV is rejected before analysis
    Given I am authenticated as a Maintenance Team member
    When I upload a file with missing required columns
    Then I receive error "Struktur CSV tidak sesuai" with a list of required columns
    And no analysis batch is created
    And the upload button becomes active again after selecting a valid file

  Scenario: Analysis service failure marks batch as Gagal
    Given I am authenticated as a Maintenance Team member
    And the analysis service is unavailable
    When I upload a valid CSV and start analysis
    Then the analysis batch eventually has status "Gagal"
    And no partial cases are created
    And the error is recorded in the batch metadata
