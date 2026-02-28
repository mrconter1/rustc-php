// error: use of moved value
fn main() {
    let s = String::from("hello");
    let t = s;
    println!("{}", s);
}
