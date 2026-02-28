// stdout: Rust
// stdout: Rust
fn show(s: &String) {
    println!("{}", s);
}

fn main() {
    let s = String::from("Rust");
    show(&s);
    println!("{}", s);
}
