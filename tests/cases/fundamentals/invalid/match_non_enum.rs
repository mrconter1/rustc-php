// error: Cannot match on non-enum type

fn main() {
    let x = 5;
    match x {
        _ => { exit(0); }
    }
}
