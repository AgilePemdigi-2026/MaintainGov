@http @sprint1 @uc01
Feature: Sprint 1 feedback analysis and persistence
  Only Maintenance members can submit feedback files.
  A successful analysis persists Pending cases linked to source reviews.

  Scenario: Invalid CSV headers are rejected
    Given a fresh unauthenticated API session
    When I log in as "maintenance"
    And I submit a CSV with missing required columns
    Then the HTTP response is 422

  Scenario: Valid CSV creates cases and repeat upload does not duplicate them
    Given a fresh unauthenticated API session
    When I log in as "maintenance"
    And I submit the valid feedback fixture
    Then the batch eventually becomes "Selesai"
    And Pending cases are linked to their source reviews
    When I upload the same CSV with the same idempotency key
    Then the persisted review and case identifiers remain unchanged
