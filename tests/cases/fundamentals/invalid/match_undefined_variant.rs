// error: Enum 'Color' has no variant 'Purple'

enum Color {
    Red,
    Green,
    Blue,
}

fn main() {
    let c = Color::Red;
    match c {
        Color::Purple => { exit(1); }
        _ => { exit(0); }
    }
}
