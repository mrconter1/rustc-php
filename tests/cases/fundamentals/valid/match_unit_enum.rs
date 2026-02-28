// exit: 0
// stdout: north
// stdout: south
// stdout: east
// stdout: other

enum Direction {
    North,
    South,
    East,
    West,
}

fn describe(d: Direction) {
    match d {
        Direction::North => { println!("north"); }
        Direction::South => { println!("south"); }
        Direction::East  => { println!("east"); }
        _ => { println!("other"); }
    }
}

fn main() {
    describe(Direction::North);
    describe(Direction::South);
    describe(Direction::East);
    describe(Direction::West);
}
