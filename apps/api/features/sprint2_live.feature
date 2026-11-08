@http @sprint2 @integration
Feature: Sprint 2 Maintenance Case to Developer claim (real Laravel and MySQL)
  UC02 and UC03 must persist before a Developer can claim the backlog.
  GitHub write is intentionally excluded from normal CI.

  Scenario: CSV creates a case, Maintenance validates and edits, Developer claims
    Given a fresh unauthenticated API session
    When I log in as "maintenance"
    And I submit the valid feedback fixture
    Then the batch eventually becomes "Selesai"
    And Pending cases are linked to their source reviews
    When I validate the first newly generated case as Valid and edit the backlog
    Then the sprint2 backlog persists with status "Ready for Development"
    When I log out and log in as developer for sprint2
    And I claim that sprint2 backlog
    Then that sprint2 issue is assigned and awaiting GitHub push
