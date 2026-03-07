// exit: 0
// stdout: 1
// stdout: 2
// stdout: 3

enum State {
    One,
    Two,
    Three,
    Done,
}

fn main() {
    let mut state = State::One;
    loop {
        match state {
            State::One   => { println!("1"); state = State::Two; }
            State::Two   => { println!("2"); state = State::Three; }
            State::Three => { println!("3"); state = State::Done; }
            State::Done  => { break; }
        }
    }
}
