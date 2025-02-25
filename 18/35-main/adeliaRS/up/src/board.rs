// src/board.rs

pub struct BoardInfo {
    pub id: i32,
    pub name: &'static str,
}

pub const BOARDS: &[BoardInfo] = &[
    BoardInfo { id: 1, name: "Kings Gambit" },
    BoardInfo { id: 2, name: "Queens Gambit" },
    BoardInfo { id: 3, name: "Openings" },
    // Add more boards as needed
];

/// Retrieves the name of the board based on its ID.
/// Returns `Some(&str)` if the board is defined, otherwise `None`.
pub fn get_board_name(id: i32) -> Option<&'static str> {
    BOARDS.iter()
        .find(|board| board.id == id)
        .map(|board| board.name)
}
