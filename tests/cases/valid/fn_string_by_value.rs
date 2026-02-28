// stdout: hello
fn take(s: String) {
    println!("{}", s);
}

fn main() {
    let s = String::from("hello");
    take(s);
}
